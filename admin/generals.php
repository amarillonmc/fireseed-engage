<?php
// 种火集结号 - 武将管理页面
// 包含初始化文件
require_once '../includes/init.php';

// 检查用户是否已登录且为管理员
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user = new User($_SESSION['user_id']);
if (!$user->isValid() || !$user->isAdmin()) {
    header('Location: ../index.php');
    exit;
}

// 创建管理员管理器
$adminManager = new AdminManager($user);

// 检查权限
if (!$adminManager->hasPermission('manage_generals')) {
    die('您没有权限访问此页面');
}

$error = '';
$success = '';

/**
 * 校验并标准化公共武将模板输入 / Validate and normalize public-general template input
 * @param array $input 表单输入 / Form input
 * @return array 校验结果 / Validation result
 */
function validateAdminGeneralInput($input) {
    $validRarities = ['B', 'A', 'S', 'SS', 'P'];
    $validElements = ['亮晶晶', '暖洋洋', '冷冰冰', '郁萌萌', '昼闪闪', '夜静静'];
    $nameInput = isset($input['name']) && is_scalar($input['name'])
        ? $input['name']
        : '';
    $sourceInput = isset($input['source']) && is_scalar($input['source'])
        ? $input['source']
        : '';
    $rarityInput = isset($input['rarity']) && is_scalar($input['rarity'])
        ? $input['rarity']
        : '';
    $elementInput = isset($input['element']) && is_scalar($input['element'])
        ? $input['element']
        : '';
    $costInput = isset($input['cost']) && is_scalar($input['cost'])
        ? $input['cost']
        : null;
    $name = normalizeTextInput($nameInput, 100);
    $source = normalizeTextInput($sourceInput, 100);
    $rarity = strtoupper(trim((string) $rarityInput));
    $element = trim((string) $elementInput);
    $cost = filter_var($costInput, FILTER_VALIDATE_FLOAT);
    $hasInvalidInherentCardId = isset($input['inherent_card_id'])
        && !is_scalar($input['inherent_card_id']);
    $rawInherentCardId = !$hasInvalidInherentCardId
        && isset($input['inherent_card_id'])
        ? trim((string) $input['inherent_card_id'])
        : 'none';
    $inherentCardId = null;
    $attributes = [];
    $errors = [];

    if ($name === '') {
        $errors[] = '武将名称不能为空';
    }
    if (!in_array($rarity, $validRarities, true)) {
        $errors[] = '稀有度无效';
    }
    if (!in_array($element, $validElements, true)) {
        $errors[] = '元素无效';
    }
    if ($cost === false || $cost < 1.0 || $cost > 4.0) {
        $errors[] = 'COST 必须在 1.0 至 4.0 之间';
    }
    if ($hasInvalidInherentCardId) {
        $errors[] = '固有技能卡无效';
    } elseif (!in_array($rawInherentCardId, ['', 'none', '0'], true)) {
        $inherentCardId = filter_var(
            $rawInherentCardId,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($inherentCardId === false) {
            $errors[] = '固有技能卡无效';
            $inherentCardId = null;
        }
    }

    $attributeCap = defined('GENERAL_ATTRIBUTE_HARD_CAP')
        ? (int) GENERAL_ATTRIBUTE_HARD_CAP
        : 2000000000;
    foreach (['attack', 'defense', 'speed', 'intelligence'] as $attribute) {
        $attributeInput = isset($input[$attribute])
            && is_scalar($input[$attribute])
            ? $input[$attribute]
            : null;
        $value = filter_var(
            $attributeInput,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => $attributeCap]]
        );
        if ($value === false) {
            $errors[] = $attribute . ' 必须是有效的非负整数';
            $value = 0;
        }
        $attributes[$attribute] = $value;
    }

    return [
        'errors' => $errors,
        'data' => [
            'name' => $name,
            'source' => $source === '' ? '原创角色' : $source,
            'rarity' => $rarity,
            'cost' => $cost === false ? 1.0 : (float) $cost,
            'element' => $element,
            'inherent_card_id' => $inherentCardId,
            'attributes' => $attributes
        ]
    ];
}

/**
 * 读取并锁定可用的固有技能卡 / Load and lock an eligible inherent skill card
 * @param mysqli $db 数据库连接 / Database connection
 * @param int|null $cardId 技能卡ID / Skill-card ID
 * @param int|null $generalId 编辑中的公共模板ID / Public template ID being edited
 * @return array|null 技能卡数据 / Skill-card data
 */
function loadAdminInherentCard($db, $cardId, $generalId = null) {
    if ($cardId === null) {
        return null;
    }

    $query = "SELECT card_id, name, effect_json, is_active
              FROM skill_card_catalog
              WHERE card_id = ?
              LIMIT 1 FOR UPDATE";
    $stmt = $db->prepare($query);
    if (!$stmt) {
        throw new RuntimeException('无法校验固有技能卡');
    }

    $stmt->bind_param('i', $cardId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('无法校验固有技能卡');
    }

    $card = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$card) {
        throw new DomainException('所选固有技能卡不存在');
    }

    if ((int) $card['is_active'] !== 1) {
        if ($generalId === null) {
            throw new DomainException('创建模板时不能选择已停用的固有技能卡');
        }

        // 停用卡仅可作为当前模板既有映射原样保留 / An inactive card may only preserve the current template's existing mapping
        $query = "SELECT gs.skill_id, esc.card_id
                  FROM general_skills gs
                  JOIN equipped_skill_cards esc ON esc.skill_id = gs.skill_id
                  WHERE gs.general_id = ?
                    AND gs.slot = 0
                    AND gs.skill_type = '自带'
                  ORDER BY gs.skill_id
                  LIMIT 1 FOR UPDATE";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new RuntimeException('无法校验历史固有技能映射');
        }
        $stmt->bind_param('i', $generalId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('无法校验历史固有技能映射');
        }
        $currentMapping = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$currentMapping
            || (int) $currentMapping['card_id'] !== (int) $card['card_id']) {
            throw new DomainException('已停用技能卡只能保留在其原有模板上');
        }
    }

    $nameLength = function_exists('mb_strlen')
        ? mb_strlen($card['name'], 'UTF-8')
        : preg_match_all('/./us', $card['name'], $matches);
    if ($nameLength === false || $nameLength > 50) {
        throw new DomainException('所选技能名称超过固有技能的50字符上限');
    }

    $effect = json_decode($card['effect_json']);
    if (json_last_error() !== JSON_ERROR_NONE || !is_object($effect)) {
        throw new DomainException('所选技能的效果数据无效');
    }

    return $card;
}

/**
 * 同步模板的零号槽固有技能及目录映射 / Synchronize slot-zero inherent skill and catalog mapping
 * @param mysqli $db 数据库连接 / Database connection
 * @param int $generalId 公共模板武将ID / Public template general ID
 * @param array|null $card 技能卡数据，null表示移除 / Card data, or null to remove
 * @return void
 */
function synchronizeAdminInherentSkill($db, $generalId, $card) {
    // 在写入技能前再次锁定公共模板，形成防御式归属边界 / Re-lock the public template before skill writes as a defensive ownership boundary
    $stmt = $db->prepare(
        'SELECT general_id
         FROM generals
         WHERE general_id = ? AND owner_id = 0
         LIMIT 1 FOR UPDATE'
    );
    if (!$stmt) {
        throw new RuntimeException('无法校验模板固有技能归属');
    }
    $stmt->bind_param('i', $generalId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('无法校验模板固有技能归属');
    }
    $publicTemplate = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$publicTemplate) {
        throw new DomainException('公共武将模板不存在');
    }

    $query = "SELECT skill_id
              FROM general_skills
              WHERE general_id = ? AND slot = 0
              ORDER BY skill_id
              LIMIT 1 FOR UPDATE";
    $stmt = $db->prepare($query);
    if (!$stmt) {
        throw new RuntimeException('无法读取模板固有技能');
    }
    $stmt->bind_param('i', $generalId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('无法读取模板固有技能');
    }
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($card === null) {
        $stmt = $db->prepare(
            'DELETE FROM general_skills WHERE general_id = ? AND slot = 0'
        );
        if (!$stmt) {
            throw new RuntimeException('无法移除模板固有技能');
        }
        $stmt->bind_param('i', $generalId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('无法移除模板固有技能');
        }
        $stmt->close();
        return;
    }

    $skillName = (string) $card['name'];
    $skillEffect = (string) $card['effect_json'];
    if ($existing) {
        $skillId = (int) $existing['skill_id'];
        $query = "UPDATE general_skills
                  SET skill_type = '自带', skill_name = ?,
                      skill_level = 1, skill_effect = ?
                  WHERE skill_id = ? AND general_id = ? AND slot = 0";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new RuntimeException('无法更新模板固有技能');
        }
        $stmt->bind_param(
            'ssii',
            $skillName,
            $skillEffect,
            $skillId,
            $generalId
        );
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('无法更新模板固有技能');
        }
        $stmt->close();

        // 清理历史重复零号槽，确保每个模板只有一个固有技能 / Remove legacy duplicate slot-zero rows so each template has one inherent skill
        $stmt = $db->prepare(
            'DELETE FROM general_skills
             WHERE general_id = ? AND slot = 0 AND skill_id <> ?'
        );
        if (!$stmt) {
            throw new RuntimeException('无法清理重复的模板固有技能');
        }
        $stmt->bind_param('ii', $generalId, $skillId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('无法清理重复的模板固有技能');
        }
        $stmt->close();
    } else {
        $query = "INSERT INTO general_skills
                    (general_id, skill_type, skill_name, slot, skill_level, skill_effect)
                  VALUES (?, '自带', ?, 0, 1, ?)";
        $stmt = $db->prepare($query);
        if (!$stmt) {
            throw new RuntimeException('无法创建模板固有技能');
        }
        $stmt->bind_param('iss', $generalId, $skillName, $skillEffect);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('无法创建模板固有技能');
        }
        $skillId = (int) $db->insert_id;
        $stmt->close();
    }

    $cardId = (int) $card['card_id'];
    $query = "INSERT INTO equipped_skill_cards (skill_id, card_id)
              VALUES (?, ?)
              ON DUPLICATE KEY UPDATE
                card_id = VALUES(card_id), equipped_at = CURRENT_TIMESTAMP";
    $stmt = $db->prepare($query);
    if (!$stmt) {
        throw new RuntimeException('无法映射模板固有技能卡');
    }
    $stmt->bind_param('ii', $skillId, $cardId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('无法映射模板固有技能卡');
    }
    $stmt->close();
}

// 处理武将操作 / Handle general-template mutations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        http_response_code(403);
        $error = '请求安全令牌无效，请刷新页面后重试';
    } else {
        $db = Database::getInstance()->getConnection();
        $transactionOpen = false;
        $action = isset($_POST['action']) && is_scalar($_POST['action'])
            ? (string) $_POST['action']
            : '';
        $validated = in_array($action, ['create_general', 'update_general'], true)
            ? validateAdminGeneralInput($_POST)
            : ['errors' => [], 'data' => []];

        try {
            if (!empty($validated['errors'])) {
                throw new InvalidArgumentException(
                    implode('；', $validated['errors'])
                );
            }

            if ($action === 'create_general') {
                if (!$adminManager->hasPermission('create_generals')) {
                    throw new DomainException('您没有权限创建武将');
                }

                $data = $validated['data'];
                if (!$db->begin_transaction()) {
                    throw new RuntimeException('无法开始模板创建事务');
                }
                $transactionOpen = true;
                $card = loadAdminInherentCard(
                    $db,
                    $data['inherent_card_id']
                );

                // owner_id=0 表示公共原创模板 / owner_id=0 denotes a public OC template
                $query = "INSERT INTO generals
                            (owner_id, name, source, rarity, cost, element,
                             hp, max_hp, attack, defense, speed, intelligence)
                          VALUES
                            (0, ?, ?, ?, ?, ?, 100, 100, ?, ?, ?, ?)";
                $stmt = $db->prepare($query);
                if (!$stmt) {
                    throw new RuntimeException('无法准备武将模板创建操作');
                }
                $stmt->bind_param(
                    'sssdsiiii',
                    $data['name'],
                    $data['source'],
                    $data['rarity'],
                    $data['cost'],
                    $data['element'],
                    $data['attributes']['attack'],
                    $data['attributes']['defense'],
                    $data['attributes']['speed'],
                    $data['attributes']['intelligence']
                );
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('武将模板创建失败');
                }
                $generalId = (int) $db->insert_id;
                $stmt->close();

                synchronizeAdminInherentSkill($db, $generalId, $card);
                if (!$db->commit()) {
                    throw new RuntimeException('武将模板创建事务提交失败');
                }
                $transactionOpen = false;
                $success = '公共武将模板创建成功';
                $user->logAdminAction(
                    'create_general',
                    'general',
                    $generalId,
                    'Created template: ' . $data['name']
                );
            } elseif ($action === 'update_general') {
                if (!$adminManager->hasPermission('edit_generals')) {
                    throw new DomainException('您没有权限编辑武将');
                }

                $generalId = filter_var(
                    isset($_POST['general_id'])
                        && is_scalar($_POST['general_id'])
                        ? $_POST['general_id']
                        : null,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );
                if ($generalId === false) {
                    throw new DomainException('公共武将模板不存在');
                }

                $data = $validated['data'];
                if (!$db->begin_transaction()) {
                    throw new RuntimeException('无法开始模板更新事务');
                }
                $transactionOpen = true;

                // 锁定并再次校验模板归属，阻止并发请求越权更新玩家武将 / Lock and revalidate ownership to prevent concurrent updates from touching player generals
                $stmt = $db->prepare(
                    'SELECT owner_id
                     FROM generals
                     WHERE general_id = ?
                     LIMIT 1 FOR UPDATE'
                );
                if (!$stmt) {
                    throw new RuntimeException('无法校验公共武将模板');
                }
                $stmt->bind_param('i', $generalId);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('无法校验公共武将模板');
                }
                $template = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$template || (int) $template['owner_id'] !== 0) {
                    throw new DomainException('公共武将模板不存在');
                }

                $card = loadAdminInherentCard(
                    $db,
                    $data['inherent_card_id'],
                    $generalId
                );
                $query = "UPDATE generals
                          SET name = ?, source = ?, rarity = ?, cost = ?,
                              element = ?, attack = ?, defense = ?, speed = ?,
                              intelligence = ?
                          WHERE general_id = ? AND owner_id = 0";
                $stmt = $db->prepare($query);
                if (!$stmt) {
                    throw new RuntimeException('无法准备武将模板更新操作');
                }
                $stmt->bind_param(
                    'sssdsiiiii',
                    $data['name'],
                    $data['source'],
                    $data['rarity'],
                    $data['cost'],
                    $data['element'],
                    $data['attributes']['attack'],
                    $data['attributes']['defense'],
                    $data['attributes']['speed'],
                    $data['attributes']['intelligence'],
                    $generalId
                );
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('武将模板更新失败');
                }
                $stmt->close();

                synchronizeAdminInherentSkill($db, $generalId, $card);
                if (!$db->commit()) {
                    throw new RuntimeException('武将模板更新事务提交失败');
                }
                $transactionOpen = false;
                $success = '公共武将模板更新成功';
                $user->logAdminAction(
                    'update_general',
                    'general',
                    $generalId,
                    'Updated template: ' . $data['name']
                );
            } elseif ($action === 'delete_general') {
                $generalId = (int) ($_POST['general_id'] ?? 0);
                $general = new General($generalId);
                if (!$adminManager->hasPermission('delete_generals')) {
                    throw new DomainException('您没有权限删除武将');
                }
                if (!$general->isValid()
                    || (int) $general->getOwnerId() !== 0) {
                    throw new DomainException('公共武将模板不存在');
                }
                if (!$general->delete()) {
                    throw new DomainException(
                        '武将已被招募记录引用，无法删除；可保留模板以维护历史'
                    );
                }

                $success = '公共武将模板删除成功';
                $user->logAdminAction(
                    'delete_general',
                    'general',
                    $generalId,
                    'Deleted public template'
                );
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
                'admin/generals.php mutation failed: '
                . $exception->getMessage()
            );
            $error = '武将模板操作失败，请检查数据库状态后重试';
        }
    }
}

// 获取搜索参数
$search = $_GET['search'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// 获取武将列表
$db = Database::getInstance()->getConnection();
$whereClause = 'WHERE owner_id = 0';
$params = [];

if ($search) {
    $whereClause .= " AND (name LIKE ? OR source LIKE ?)";
    $searchParam = "%$search%";
    $params = [$searchParam, $searchParam];
}

$query = "SELECT * FROM generals $whereClause ORDER BY general_id DESC LIMIT ? OFFSET ?";
$stmt = $db->prepare($query);
if ($search) {
    $stmt->bind_param('ssii', $params[0], $params[1], $limit, $offset);
} else {
    $stmt->bind_param('ii', $limit, $offset);
}
$stmt->execute();
$result = $stmt->get_result();

$generals = [];
while ($row = $result->fetch_assoc()) {
    $generals[] = $row;
}
$stmt->close();

// 获取总数
$countQuery = "SELECT COUNT(*) as total FROM generals $whereClause";
$countStmt = $db->prepare($countQuery);
if ($search) {
    $countStmt->bind_param('ss', $params[0], $params[1]);
}
$countStmt->execute();
$totalCount = $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

$totalPages = ceil($totalCount / $limit);

// 只向管理员提供当前启用的目录卡 / Offer administrators only currently active catalog cards
$inherentCards = [];
$cardStmt = $db->prepare(
    "SELECT card_id, name, rarity, element, activation_type
     FROM skill_card_catalog
     WHERE is_active = 1
     ORDER BY FIELD(rarity, 'P', 'SS', 'S', 'A', 'B'), name, card_id"
);
if ($cardStmt && $cardStmt->execute()) {
    $cardResult = $cardStmt->get_result();
    while ($cardResult && ($card = $cardResult->fetch_assoc())) {
        $inherentCards[] = $card;
    }
    $cardStmt->close();
} else {
    if ($cardStmt) {
        $cardStmt->close();
    }
    if ($error === '') {
        $error = '无法读取可用的固有技能卡';
    }
}

$pageTitle = '武将管理';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - <?php echo $pageTitle; ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .admin-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .admin-header {
            background: linear-gradient(135deg, #9b59b6 0%, #8e44ad 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-title {
            font-size: 24px;
            font-weight: bold;
        }
        
        .back-link {
            color: white;
            text-decoration: none;
            padding: 8px 16px;
            background: rgba(255,255,255,0.2);
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        
        .back-link:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .search-section {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .search-form {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .search-input {
            flex: 1;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
        }
        
        .search-button {
            padding: 10px 20px;
            background: #9b59b6;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }
        
        .search-button:hover {
            background: #8e44ad;
        }
        
        .generals-section {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .generals-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .generals-table th,
        .generals-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .generals-table th {
            background: #f8f9fa;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .generals-table tr:hover {
            background: #f8f9fa;
        }
        
        .rarity-badge {
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
        }
        
        .rarity-B { background: #95a5a6; color: white; }
        .rarity-A { background: #3498db; color: white; }
        .rarity-S { background: #9b59b6; color: white; }
        .rarity-SS { background: #f39c12; color: white; }
        .rarity-P { background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); color: white; }
        
        .element-badge {
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            display: inline-block;
        }
        
        .element-亮晶晶 { background: #ecf0f1; color: #2c3e50; }
        .element-暖洋洋 { background: #ffebee; color: #c62828; }
        .element-冷冰冰 { background: #e3f2fd; color: #1565c0; }
        .element-郁萌萌 { background: #e8f5e8; color: #2e7d32; }
        .element-昼闪闪 { background: #fffde7; color: #f57f17; }
        .element-夜静静 { background: #4a148c; color: white; }
        
        .action-button {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
            margin: 2px;
            transition: background-color 0.3s;
        }
        
        .btn-primary {
            background: #9b59b6;
            color: white;
        }
        
        .btn-primary:hover {
            background: #8e44ad;
        }
        
        .btn-success {
            background: #27ae60;
            color: white;
        }
        
        .btn-success:hover {
            background: #229954;
        }
        
        .btn-danger {
            background: #e74c3c;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c0392b;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
        }
        
        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #2c3e50;
        }
        
        .pagination a:hover {
            background: #f8f9fa;
        }
        
        .pagination .current {
            background: #9b59b6;
            color: white;
            border-color: #9b59b6;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 20px;
            border-radius: 8px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .modal-title {
            font-size: 18px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            color: #000;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .form-input,
        .form-select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        .error {
            background: #ffebee;
            color: #c62828;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #c62828;
        }
        
        .success {
            background: #e8f5e8;
            color: #2e7d32;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #2e7d32;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .generals-table {
                font-size: 14px;
            }
            
            .generals-table th,
            .generals-table td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="admin-container">
            <!-- 页面头部 -->
            <div class="admin-header">
                <div class="header-title">⚔️ 公共武将模板管理</div>
                <a href="index.php" class="back-link">← 返回管理后台</a>
            </div>
            
            <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <!-- 搜索区域 -->
            <div class="search-section">
                <form class="search-form" method="get">
                    <input type="text" name="search" class="search-input" 
                           placeholder="搜索武将名称或来源..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="search-button">搜索</button>
                    <?php if ($search): ?>
                    <a href="generals.php" class="search-button" style="background: #95a5a6;">清除</a>
                    <?php endif; ?>
                    <?php if ($adminManager->hasPermission('create_generals')): ?>
                    <button type="button" class="search-button" style="background: #27ae60;" onclick="showCreateModal()">
                        + 创建武将
                    </button>
                    <?php endif; ?>
                </form>
            </div>
            
            <!-- 武将列表 -->
            <div class="generals-section">
                <h3>武将列表 (共 <?php echo number_format($totalCount); ?> 个武将)</h3>
                
                <?php if (!empty($generals)): ?>
                <table class="generals-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>武将名称</th>
                            <th>来源</th>
                            <th>稀有度</th>
                            <th>元素</th>
                            <th>COST</th>
                            <th>攻击</th>
                            <th>防御</th>
                            <th>速度</th>
                            <th>智力</th>
                            <th>等级</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($generals as $general): ?>
                        <tr>
                            <td><?php echo $general['general_id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($general['name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($general['source']); ?></td>
                            <td>
                                <span class="rarity-badge rarity-<?php echo $general['rarity']; ?>">
                                    <?php echo $general['rarity']; ?>
                                </span>
                            </td>
                            <td>
                                <span class="element-badge element-<?php echo $general['element']; ?>">
                                    <?php echo $general['element']; ?>
                                </span>
                            </td>
                            <td><?php echo number_format($general['cost'], 1); ?></td>
                            <td><?php echo $general['attack']; ?></td>
                            <td><?php echo $general['defense']; ?></td>
                            <td><?php echo $general['speed']; ?></td>
                            <td><?php echo $general['intelligence']; ?></td>
                            <td>Lv.<?php echo $general['level']; ?></td>
                            <td>
                                <?php if ($adminManager->hasPermission('edit_generals')): ?>
                                <button class="action-button btn-primary" 
                                        onclick="editGeneral(<?php echo $general['general_id']; ?>)">
                                    编辑
                                </button>
                                <?php endif; ?>
                                
                                <?php if ($adminManager->hasPermission('delete_generals')): ?>
                                <button
                                        type="button"
                                        class="action-button btn-danger delete-general-button"
                                        data-general-id="<?php echo (int) $general['general_id']; ?>"
                                        data-general-name="<?php echo escapeHtml($general['name']); ?>">
                                    删除
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- 分页 -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                    <a href="generals.php?page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">上一页</a>
                    <?php endif; ?>
                    
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                    <?php if ($i == $page): ?>
                    <span class="current"><?php echo $i; ?></span>
                    <?php else: ?>
                    <a href="generals.php?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>"><?php echo $i; ?></a>
                    <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                    <a href="generals.php?page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>">下一页</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <?php else: ?>
                <div style="text-align: center; color: #7f8c8d; padding: 40px;">
                    <?php echo $search ? '未找到匹配的武将' : '暂无武将'; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- 创建/编辑武将模态框 -->
    <div id="generalModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title" id="modalTitle">创建武将</div>
                <span class="close" onclick="closeModal('generalModal')">&times;</span>
            </div>
            <form id="generalForm" method="post">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" id="formAction" value="create_general">
                <input type="hidden" name="general_id" id="edit_general_id">
                
                <div class="form-group">
                    <label class="form-label">武将名称</label>
                    <input type="text" name="name" id="general_name" class="form-input" maxlength="100" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">来源</label>
                    <input type="text" name="source" id="general_source" class="form-input" maxlength="100" placeholder="例如：企划角色设定集 / e.g. Project OC setting">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">稀有度</label>
                        <select name="rarity" id="general_rarity" class="form-select" required>
                            <option value="B">B - 普通</option>
                            <option value="A">A - 稀有</option>
                            <option value="S">S - 史诗</option>
                            <option value="SS">SS - 传说</option>
                            <option value="P">P - 白金</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">COST</label>
                        <input type="number" name="cost" id="general_cost" class="form-input" 
                               step="0.5" min="1.0" max="4.0" value="1.0" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">元素</label>
                    <select name="element" id="general_element" class="form-select" required>
                        <option value="亮晶晶">亮晶晶 - 内政型</option>
                        <option value="暖洋洋">暖洋洋 - 速攻型</option>
                        <option value="冷冰冰">冷冰冰 - 防御型</option>
                        <option value="郁萌萌">郁萌萌 - 强攻型</option>
                        <option value="昼闪闪">昼闪闪 - 辅助型</option>
                        <option value="夜静静">夜静静 - 特殊型</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">固有技能（零号槽）</label>
                    <select name="inherent_card_id" id="general_inherent_card_id" class="form-select">
                        <option value="none">无固有技能</option>
                        <?php foreach ($inherentCards as $card): ?>
                        <option value="<?php echo (int) $card['card_id']; ?>">
                            <?php echo escapeHtml(
                                '[' . $card['rarity'] . ' / '
                                . $card['element'] . '] '
                                . $card['name']
                                . '（'
                                . ($card['activation_type'] === 'active'
                                    ? '主动'
                                    : '被动')
                                . '）'
                            ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="display:block; margin-top:6px; color:#7f8c8d;">
                        保存后会同步模板零号槽；选择“无”会移除原固有技能。
                    </small>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">攻击力</label>
                        <input type="number" name="attack" id="general_attack" class="form-input" min="0" max="2000000000" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">防御力</label>
                        <input type="number" name="defense" id="general_defense" class="form-input" min="0" max="2000000000" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">速度</label>
                        <input type="number" name="speed" id="general_speed" class="form-input" min="0" max="2000000000" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">智力</label>
                        <input type="number" name="intelligence" id="general_intelligence" class="form-input" min="0" max="2000000000" required>
                    </div>
                </div>
                
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" onclick="closeModal('generalModal')" 
                            style="margin-right: 10px; padding: 8px 16px; background: #95a5a6; color: white; border: none; border-radius: 4px;">
                        取消
                    </button>
                    <button type="submit" class="action-button btn-success">保存</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // 移除上一次编辑动态加入的历史选项 / Remove the historical option injected for the previous edit
        function removeHistoricalInherentOption() {
            document.querySelectorAll(
                '#general_inherent_card_id option[data-historical-inherent="1"]'
            ).forEach(function(option) {
                option.remove();
            });
        }

        function showCreateModal() {
            removeHistoricalInherentOption();
            document.getElementById('modalTitle').textContent = '创建武将';
            document.getElementById('formAction').value = 'create_general';
            document.getElementById('edit_general_id').value = '';
            
            // 清空表单 / Clear the form
            document.getElementById('general_name').value = '';
            document.getElementById('general_source').value = '';
            document.getElementById('general_rarity').value = 'B';
            document.getElementById('general_cost').value = '1.0';
            document.getElementById('general_element').value = '亮晶晶';
            document.getElementById('general_inherent_card_id').value = 'none';
            document.getElementById('general_attack').value = '';
            document.getElementById('general_defense').value = '';
            document.getElementById('general_speed').value = '';
            document.getElementById('general_intelligence').value = '';
            
            document.getElementById('generalModal').style.display = 'block';
        }
        
        function editGeneral(generalId) {
            fetch('../api/get_general.php?general_id=' + generalId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('modalTitle').textContent = '编辑武将';
                        document.getElementById('formAction').value = 'update_general';
                        document.getElementById('edit_general_id').value = generalId;
                        
                        const general = data.general;
                        document.getElementById('general_name').value = general.name;
                        document.getElementById('general_source').value = general.source || '';
                        document.getElementById('general_rarity').value = general.rarity;
                        document.getElementById('general_cost').value = general.cost;
                        document.getElementById('general_element').value = general.element;
                        removeHistoricalInherentOption();
                        const inherentSelect = document.getElementById('general_inherent_card_id');
                        const inherentCardId = Number(general.inherent_card_id || 0);
                        const inherentCardIsActive = Number(
                            general.inherent_card_is_active || 0
                        ) === 1;
                        let inherentOption = Array.from(inherentSelect.options)
                            .find(function(option) {
                                return option.value === String(inherentCardId);
                            });
                        if (inherentOption && !inherentCardIsActive) {
                            inherentOption.remove();
                            inherentOption = null;
                        }
                        if (!inherentOption && inherentCardId > 0) {
                            inherentOption = document.createElement('option');
                            inherentOption.value = String(inherentCardId);
                            inherentOption.dataset.historicalInherent = '1';
                            inherentOption.textContent = inherentCardIsActive
                                ? '[当前启用 / Current active] '
                                    + (general.inherent_card_name || ('技能卡 #' + inherentCardId))
                                : '[历史停用，仅可保留或移除 / Historical inactive] '
                                    + (general.inherent_card_name || ('技能卡 #' + inherentCardId));
                            inherentSelect.appendChild(inherentOption);
                        }
                        inherentSelect.value = inherentOption && inherentCardId > 0
                            ? String(inherentCardId)
                            : 'none';
                        document.getElementById('general_attack').value = general.attack;
                        document.getElementById('general_defense').value = general.defense;
                        document.getElementById('general_speed').value = general.speed;
                        document.getElementById('general_intelligence').value = general.intelligence;
                        
                        document.getElementById('generalModal').style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('获取武将信息失败');
                });
        }
        
        function deleteGeneral(generalId, generalName) {
            if (confirm('确定要删除公共武将模板「' + generalName + '」吗？已被招募记录引用的模板不会被删除。')) {
                const form = document.createElement('form');
                form.method = 'POST';
                const fields = {
                    action: 'delete_general',
                    general_id: String(generalId),
                    csrf_token: <?php echo json_encode(getCsrfToken()); ?>
                };
                Object.keys(fields).forEach(function(name) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = fields[name];
                    form.appendChild(input);
                });
                document.body.appendChild(form);
                form.submit();
            }
        }

        document.querySelectorAll('.delete-general-button').forEach(function(button) {
            button.addEventListener('click', function() {
                deleteGeneral(
                    Number(button.dataset.generalId),
                    button.dataset.generalName || ''
                );
            });
        });
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // 点击模态框外部关闭 / Close the modal when its backdrop is clicked
        window.onclick = function(event) {
            const modal = document.getElementById('generalModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        }
    </script>
</body>
</html>

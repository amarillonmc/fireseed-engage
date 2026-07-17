<?php
// 种火集结号 - 获取武将信息 API
require_once '../includes/init.php';

header('Content-Type: application/json');

// 检查用户是否已登录
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => '未登录']);
    exit;
}

$user = new User($_SESSION['user_id']);
if (!$user->isValid() || !$user->isAdmin()) {
    echo json_encode(['success' => false, 'message' => '无权限']);
    exit;
}
$adminManager = new AdminManager($user);
if (!$adminManager->hasPermission('manage_generals')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '无权限']);
    exit;
}

// 获取武将ID
$generalId = isset($_GET['general_id']) && is_scalar($_GET['general_id'])
    ? intval($_GET['general_id'])
    : 0;

if ($generalId <= 0) {
    echo json_encode(['success' => false, 'message' => '无效的武将ID']);
    exit;
}

// 获取武将信息
$general = new General($generalId);

if (!$general->isValid() || (int) $general->getOwnerId() !== 0) {
    echo json_encode(['success' => false, 'message' => '武将不存在']);
    exit;
}

// 读取模板当前的固有技能卡映射，包括后来被停用的卡片 / Read the template's current inherent-card mapping, including cards disabled later
$db = Database::getInstance()->getConnection();
$query = "SELECT esc.card_id, card.name, card.is_active
          FROM general_skills gs
          JOIN equipped_skill_cards esc ON esc.skill_id = gs.skill_id
          JOIN skill_card_catalog card ON card.card_id = esc.card_id
          WHERE gs.general_id = ?
            AND gs.slot = 0
            AND gs.skill_type = '自带'
          ORDER BY gs.skill_id
          LIMIT 1";
$stmt = $db->prepare($query);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '无法读取固有技能']);
    exit;
}
$stmt->bind_param('i', $generalId);
if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => '无法读取固有技能']);
    exit;
}
$inherentSkill = $stmt->get_result()->fetch_assoc();
$stmt->close();
$inherentCardId = $inherentSkill
    ? (int) $inherentSkill['card_id']
    : null;
$inherentCardName = $inherentSkill
    ? (string) $inherentSkill['name']
    : null;
$inherentCardIsActive = $inherentSkill
    ? (int) $inherentSkill['is_active']
    : null;

// 返回武将数据
echo json_encode([
    'success' => true,
    'message' => '公共武将模板读取成功 / Public general template loaded',
    'general' => [
        'general_id' => $general->getGeneralId(),
        'owner_id' => $general->getOwnerId(),
        'name' => $general->getName(),
        'source' => $general->getSource(),
        'rarity' => $general->getRarity(),
        'cost' => $general->getCost(),
        'element' => $general->getElement(),
        'level' => $general->getLevel(),
        'hp' => $general->getHp(),
        'max_hp' => $general->getMaxHp(),
        'attack' => $general->getAttack(),
        'defense' => $general->getDefense(),
        'speed' => $general->getSpeed(),
        'intelligence' => $general->getIntelligence(),
        'inherent_card_id' => $inherentCardId,
        'inherent_card_name' => $inherentCardName,
        'inherent_card_is_active' => $inherentCardIsActive
    ]
]);

<?php
// 种火集结号 - 获取技能卡目录信息API / Fireseed Engage - Skill-card catalog detail API
require_once '../includes/init.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

/**
 * 返回统一的JSON响应并终止请求 / Returns a consistent JSON response and exits
 *
 * @param int $status HTTP状态码 / HTTP status code
 * @param array $payload 响应数据 / Response payload
 * @return void
 */
function respondSkillCatalogJson($status, array $payload) {
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    respondSkillCatalogJson(405, [
        'success' => false,
        'message' => '仅支持GET请求'
    ]);
}

if (!isset($_SESSION['user_id'])) {
    respondSkillCatalogJson(401, [
        'success' => false,
        'message' => '未登录'
    ]);
}

$user = new User($_SESSION['user_id']);
if (!$user->isValid() || !$user->isAdmin()) {
    respondSkillCatalogJson(403, [
        'success' => false,
        'message' => '无权限'
    ]);
}

$adminManager = new AdminManager($user);
if (!$adminManager->hasPermission('manage_skills')) {
    respondSkillCatalogJson(403, [
        'success' => false,
        'message' => '无权限'
    ]);
}

$rawCardId = $_GET['card_id'] ?? '';
if (!is_scalar($rawCardId)) {
    respondSkillCatalogJson(400, [
        'success' => false,
        'message' => '无效的技能卡ID'
    ]);
}

$cardId = filter_var(
    (string) $rawCardId,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
if ($cardId === false) {
    respondSkillCatalogJson(400, [
        'success' => false,
        'message' => '无效的技能卡ID'
    ]);
}

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare(
    'SELECT card_id, card_code, name, description, rarity, element,
            activation_type, category, effect_json, base_cooldown,
            max_level, is_active
     FROM skill_card_catalog
     WHERE card_id = ?
     LIMIT 1'
);
if (!$stmt) {
    error_log('api/get_skill.php failed to prepare catalog query');
    respondSkillCatalogJson(500, [
        'success' => false,
        'message' => '无法读取技能卡信息'
    ]);
}

$stmt->bind_param('i', $cardId);
if (!$stmt->execute()) {
    error_log('api/get_skill.php catalog query failed: ' . $stmt->error);
    $stmt->close();
    respondSkillCatalogJson(500, [
        'success' => false,
        'message' => '无法读取技能卡信息'
    ]);
}

$result = $stmt->get_result();
$card = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$card) {
    respondSkillCatalogJson(404, [
        'success' => false,
        'message' => '技能卡不存在'
    ]);
}

$effect = json_decode((string) $card['effect_json']);
if (json_last_error() !== JSON_ERROR_NONE || !is_object($effect)) {
    error_log(
        'api/get_skill.php found invalid effect_json for card_id='
        . (int) $card['card_id']
    );
    respondSkillCatalogJson(500, [
        'success' => false,
        'message' => '技能卡效果数据无效'
    ]);
}

respondSkillCatalogJson(200, [
    'success' => true,
    'message' => '技能卡信息读取成功',
    'card' => [
        'card_id' => (int) $card['card_id'],
        'card_code' => (string) $card['card_code'],
        'name' => (string) $card['name'],
        'description' => (string) $card['description'],
        'rarity' => (string) $card['rarity'],
        'element' => (string) $card['element'],
        'activation_type' => (string) $card['activation_type'],
        'category' => (string) $card['category'],
        'effect' => $effect,
        'base_cooldown' => (int) $card['base_cooldown'],
        'max_level' => (int) $card['max_level'],
        'is_active' => (int) $card['is_active']
    ]
]);

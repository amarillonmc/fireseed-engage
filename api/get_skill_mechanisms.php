<?php
// 种火集结号 - 技能机制目录API / Fireseed Engage - Skill mechanism catalog API
require_once '../includes/init.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');

/**
 * 返回统一的机制目录响应并终止请求 / Returns a consistent mechanism-catalog response and exits
 *
 * @param int $status HTTP状态码 / HTTP status code
 * @param array $payload 响应数据 / Response payload
 * @return void
 */
function respondSkillMechanismJson($status, array $payload) {
    http_response_code($status);
    $encoded = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if ($encoded === false) {
        http_response_code(500);
        echo '{"success":false,"message":"机制目录编码失败 / Failed to encode mechanism catalog"}';
        exit;
    }

    echo $encoded;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    respondSkillMechanismJson(405, [
        'success' => false,
        'message' => '仅支持GET请求 / Only GET requests are supported'
    ]);
}

if (!isset($_SESSION['user_id'])) {
    respondSkillMechanismJson(401, [
        'success' => false,
        'message' => '未登录 / Not signed in'
    ]);
}

$user = new User($_SESSION['user_id']);
if (!$user->isValid() || !$user->isAdmin()) {
    respondSkillMechanismJson(403, [
        'success' => false,
        'message' => '无权限 / Permission denied'
    ]);
}

$adminManager = new AdminManager($user);
if (!$adminManager->hasPermission('manage_skills')) {
    respondSkillMechanismJson(403, [
        'success' => false,
        'message' => '无权限 / Permission denied'
    ]);
}

respondSkillMechanismJson(200, [
    'success' => true,
    'message' => '技能机制目录读取成功 / Skill mechanism catalog loaded',
    'schema_version' => SkillDefinitionValidator::SCHEMA_VERSION,
    'mechanisms' => SkillMechanismRegistry::publicCatalog(),
    'conditions' => SkillMechanismRegistry::conditions(),
    'value_modes' => SkillMechanismRegistry::valueModes(),
    'limits' => [
        'maximum_effects' => SkillDefinitionValidator::MAX_EFFECTS,
        'maximum_conditions' => SkillDefinitionValidator::MAX_CONDITIONS,
        'maximum_curve_length' => SkillValueResolver::MAX_CURVE_LENGTH,
        'maximum_depth' => SkillDefinitionValidator::MAX_DEPTH,
        'maximum_nodes' => SkillDefinitionValidator::MAX_NODES
    ]
]);

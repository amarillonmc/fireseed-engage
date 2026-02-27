<?php
// 种火集结号 - 获取技能信息 API
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

// 获取技能ID
$skillId = intval($_GET['skill_id'] ?? 0);

if ($skillId <= 0) {
    echo json_encode(['success' => false, 'message' => '无效的技能ID']);
    exit;
}

// 获取技能信息
$skill = new GeneralSkill($skillId);

if (!$skill->isValid()) {
    echo json_encode(['success' => false, 'message' => '技能不存在']);
    exit;
}

// 返回技能数据
echo json_encode([
    'success' => true,
    'skill' => [
        'skill_id' => $skill->getSkillId(),
        'general_id' => $skill->getGeneralId(),
        'skill_name' => $skill->getSkillName(),
        'skill_type' => $skill->getSkillType(),
        'slot' => $skill->getSlot(),
        'skill_level' => $skill->getSkillLevel(),
        'skill_effect' => $skill->getSkillEffect()
    ]
]);

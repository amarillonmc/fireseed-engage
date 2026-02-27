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

// 获取武将ID
$generalId = intval($_GET['general_id'] ?? 0);

if ($generalId <= 0) {
    echo json_encode(['success' => false, 'message' => '无效的武将ID']);
    exit;
}

// 获取武将信息
$general = new General($generalId);

if (!$general->isValid()) {
    echo json_encode(['success' => false, 'message' => '武将不存在']);
    exit;
}

// 返回武将数据
echo json_encode([
    'success' => true,
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
        'intelligence' => $general->getIntelligence()
    ]
]);

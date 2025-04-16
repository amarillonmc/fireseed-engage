<?php
// 包含初始化文件
require_once '../includes/init.php';

// 设置响应头
header('Content-Type: application/json');

// 检查用户是否已登录
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => '未登录'
    ]);
    exit;
}

// 获取请求参数
$battleId = isset($_GET['battle_id']) ? intval($_GET['battle_id']) : 0;

// 验证参数
if ($battleId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => '参数无效'
    ]);
    exit;
}

// 获取战斗信息
$battle = new Battle($battleId);
if (!$battle->isValid()) {
    echo json_encode([
        'success' => false,
        'message' => '战斗不存在'
    ]);
    exit;
}

// 获取攻击方军队
$attackerArmy = new Army($battle->getAttackerArmyId());
if (!$attackerArmy->isValid()) {
    echo json_encode([
        'success' => false,
        'message' => '攻击方军队不存在'
    ]);
    exit;
}

// 检查用户是否有权限查看战斗报告
if ($attackerArmy->getOwnerId() != $_SESSION['user_id']) {
    // 检查用户是否是防守方
    $defenderArmyId = $battle->getDefenderArmyId();
    $defenderCityId = $battle->getDefenderCityId();
    
    $isDefender = false;
    
    if ($defenderArmyId) {
        $defenderArmy = new Army($defenderArmyId);
        if ($defenderArmy->isValid() && $defenderArmy->getOwnerId() == $_SESSION['user_id']) {
            $isDefender = true;
        }
    }
    
    if ($defenderCityId) {
        $defenderCity = new City($defenderCityId);
        if ($defenderCity->isValid() && $defenderCity->getOwnerId() == $_SESSION['user_id']) {
            $isDefender = true;
        }
    }
    
    if (!$isDefender) {
        echo json_encode([
            'success' => false,
            'message' => '您没有权限查看该战斗报告'
        ]);
        exit;
    }
}

// 准备战斗报告数据
$battleReport = [
    'battle_id' => $battle->getBattleId(),
    'battle_time' => $battle->getBattleTime(),
    'result' => $battle->getResult(),
    'attacker' => [
        'army_id' => $attackerArmy->getArmyId(),
        'name' => $attackerArmy->getName(),
        'owner_id' => $attackerArmy->getOwnerId()
    ],
    'defender' => [],
    'attacker_losses' => $battle->getAttackerLosses(),
    'defender_losses' => $battle->getDefenderLosses(),
    'rewards' => $battle->getRewards()
];

// 获取防守方信息
$defenderArmyId = $battle->getDefenderArmyId();
$defenderCityId = $battle->getDefenderCityId();
$defenderTileId = $battle->getDefenderTileId();

if ($defenderArmyId) {
    $defenderArmy = new Army($defenderArmyId);
    if ($defenderArmy->isValid()) {
        $battleReport['defender'] = [
            'type' => 'army',
            'army_id' => $defenderArmy->getArmyId(),
            'name' => $defenderArmy->getName(),
            'owner_id' => $defenderArmy->getOwnerId()
        ];
    }
} elseif ($defenderCityId) {
    $defenderCity = new City($defenderCityId);
    if ($defenderCity->isValid()) {
        $battleReport['defender'] = [
            'type' => 'city',
            'city_id' => $defenderCity->getCityId(),
            'name' => $defenderCity->getName(),
            'owner_id' => $defenderCity->getOwnerId()
        ];
    }
} elseif ($defenderTileId) {
    $defenderTile = new Map($defenderTileId);
    if ($defenderTile->isValid()) {
        $battleReport['defender'] = [
            'type' => 'tile',
            'tile_id' => $defenderTile->getTileId(),
            'x' => $defenderTile->getX(),
            'y' => $defenderTile->getY(),
            'type' => $defenderTile->getType(),
            'subtype' => $defenderTile->getSubtype()
        ];
    }
}

// 返回战斗报告
echo json_encode([
    'success' => true,
    'battle_report' => $battleReport
]);

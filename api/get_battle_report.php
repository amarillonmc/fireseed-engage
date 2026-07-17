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

// 结算后必须按参与方快照授权，不能按易主后的当前领主授权 / Resolved reports are authorized by participant snapshots, never by a post-capture owner
if (!$battle->canUserView((int) $_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => '您没有权限查看该战斗报告'
    ]);
    exit;
}
$participant = $battle->getParticipantSnapshot();
$attackerArmy = $battle->getAttackerArmyId() === null
    ? null
    : new Army($battle->getAttackerArmyId());
$attackerOwnerId = $participant
    ? $participant['attacker_user_id']
    : ($attackerArmy && $attackerArmy->isValid()
        ? (int) $attackerArmy->getOwnerId()
        : null);

// 准备战斗报告数据
$battleReport = [
    'battle_id' => $battle->getBattleId(),
    'battle_time' => $battle->getBattleTime(),
    'result' => $battle->getResult(),
    'attacker' => [
        'army_id' => $battle->getAttackerArmyId(),
        'name' => $battle->getAttackerName(),
        'owner_id' => $attackerOwnerId,
        'power_snapshot' => $battle->getAttackerPowerSnapshot()
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
            'kind' => 'army',
            'army_id' => $defenderArmy->getArmyId(),
            'name' => $defenderArmy->getName(),
            'owner_id' => $participant
                ? $participant['defender_user_id']
                : $defenderArmy->getOwnerId()
        ];
    }
} elseif ($defenderCityId) {
    $defenderCity = new City($defenderCityId);
    if ($defenderCity->isValid()) {
        $battleReport['defender'] = [
            'kind' => 'city',
            'city_id' => $defenderCity->getCityId(),
            'name' => $defenderCity->getName(),
            'owner_id' => $participant
                ? $participant['defender_user_id']
                : $defenderCity->getOwnerId()
        ];
    }
} elseif ($defenderTileId) {
    $defenderTile = new Map($defenderTileId);
    if ($defenderTile->isValid()) {
        $battleReport['defender'] = [
            'kind' => 'tile',
            'tile_id' => $defenderTile->getTileId(),
            'x' => $defenderTile->getX(),
            'y' => $defenderTile->getY(),
            'tile_type' => $defenderTile->getType(),
            'subtype' => $defenderTile->getSubtype()
        ];
    }
}

$battleReport['counter_details'] = $participant
    ? $participant['counter_details']
    : null;

// 返回战斗报告
echo json_encode([
    'success' => true,
    'battle_report' => $battleReport
]);

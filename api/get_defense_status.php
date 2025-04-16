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
$cityId = isset($_GET['city_id']) ? intval($_GET['city_id']) : 0;

// 验证参数
if ($cityId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => '参数无效'
    ]);
    exit;
}

// 检查城池是否属于当前用户
$city = new City($cityId);
if (!$city->isValid() || $city->getOwnerId() != $_SESSION['user_id']) {
    echo json_encode([
        'success' => false,
        'message' => '城池不存在或不属于当前用户'
    ]);
    exit;
}

// 获取城池防御状态
$strategy = $city->getDefenseStrategy();
$bonus = $city->getDefenseStrategyBonus();
$defensePower = $city->getDefensePower();

// 准备返回数据
$strategyName = '';
$strategyDescription = '';

switch ($strategy) {
    case 'defense':
        $strategyName = '优先防御';
        $strategyDescription = '增加城池防御力，但减少资源产出';
        break;
    case 'production':
        $strategyName = '优先产出';
        $strategyDescription = '增加资源产出，但减少城池防御力';
        break;
    case 'balanced':
    default:
        $strategyName = '平衡';
        $strategyDescription = '城池防御力和资源产出保持平衡';
        break;
}

// 返回城池防御状态
echo json_encode([
    'success' => true,
    'defense_status' => [
        'city_id' => $cityId,
        'city_name' => $city->getName(),
        'strategy' => $strategy,
        'strategy_name' => $strategyName,
        'strategy_description' => $strategyDescription,
        'defense_bonus' => $bonus[0],
        'production_bonus' => $bonus[1],
        'defense_power' => $defensePower,
        'durability' => $city->getDurability(),
        'max_durability' => $city->getMaxDurability(),
        'level' => $city->getLevel(),
        'is_main_city' => $city->isMainCity()
    ]
]);

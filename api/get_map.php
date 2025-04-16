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
$startX = isset($_GET['start_x']) ? intval($_GET['start_x']) : 0;
$startY = isset($_GET['start_y']) ? intval($_GET['start_y']) : 0;
$endX = isset($_GET['end_x']) ? intval($_GET['end_x']) : 0;
$endY = isset($_GET['end_y']) ? intval($_GET['end_y']) : 0;

// 验证参数
if ($startX < 0 || $startX >= MAP_WIDTH || $startY < 0 || $startY >= MAP_HEIGHT ||
    $endX < 0 || $endX >= MAP_WIDTH || $endY < 0 || $endY >= MAP_HEIGHT ||
    $startX > $endX || $startY > $endY) {
    echo json_encode([
        'success' => false,
        'message' => '参数无效'
    ]);
    exit;
}

// 限制查询范围，避免一次查询过多数据
$maxRange = 20;
if ($endX - $startX > $maxRange || $endY - $startY > $maxRange) {
    echo json_encode([
        'success' => false,
        'message' => '查询范围过大'
    ]);
    exit;
}

// 获取指定范围内的地图格子
$tiles = Map::getTilesInRange($startX, $startY, $endX, $endY);

// 准备返回数据
$tileData = [];

foreach ($tiles as $tile) {
    $data = [
        'tile_id' => $tile->getTileId(),
        'x' => $tile->getX(),
        'y' => $tile->getY(),
        'type' => $tile->getType(),
        'subtype' => $tile->getSubtype(),
        'is_visible' => $tile->isVisible()
    ];
    
    // 如果地图格子可见，添加更多信息
    if ($tile->isVisible()) {
        $data['name'] = $tile->getName();
        $data['description'] = $tile->getDescription();
        
        // 根据地图格子类型添加额外信息
        switch ($tile->getType()) {
            case 'resource':
                $data['resource_amount'] = $tile->getResourceAmount();
                break;
            case 'npc_fort':
                $data['npc_level'] = $tile->getNpcLevel();
                break;
        }
        
        // 如果有拥有者，添加拥有者信息
        $ownerId = $tile->getOwnerId();
        if ($ownerId) {
            $data['owner_id'] = $ownerId;
            
            // 获取拥有者名称
            $owner = new User($ownerId);
            if ($owner->isValid()) {
                $data['owner_name'] = $owner->getUsername();
            }
            
            // 如果是玩家城池，添加城池ID
            if ($tile->getType() == 'player_city') {
                // 获取该坐标的城池ID
                $query = "SELECT city_id FROM cities WHERE x = ? AND y = ?";
                $stmt = $db->prepare($query);
                $x = $tile->getX();
                $y = $tile->getY();
                $stmt->bind_param('ii', $x, $y);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result && $result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $data['city_id'] = $row['city_id'];
                }
                
                $stmt->close();
            }
        }
    }
    
    $tileData[] = $data;
}

// 返回地图数据
echo json_encode([
    'success' => true,
    'tiles' => $tileData
]);

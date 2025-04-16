<?php
// 种火集结号 - 资源收集器类

class ResourceCollector {
    private $db;
    
    /**
     * 构造函数
     */
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * 收集指定用户的所有资源点资源
     * @param int $userId 用户ID
     * @return array 收集结果
     */
    public function collectResourcesForUser($userId) {
        // 获取用户信息
        $user = new User($userId);
        if (!$user->isValid()) {
            return [
                'success' => false,
                'message' => '用户信息无效'
            ];
        }
        
        // 获取用户拥有的所有资源点
        $query = "SELECT tile_id FROM map_tiles WHERE owner_id = ? AND type = 'resource'";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $collectedResources = [
            'bright' => 0,
            'warm' => 0,
            'cold' => 0,
            'green' => 0,
            'day' => 0,
            'night' => 0
        ];
        
        $totalCollected = 0;
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $tile = new Map($row['tile_id']);
                if ($tile->isValid()) {
                    $resourceType = $tile->getSubtype();
                    $collected = $tile->collectResource($userId);
                    
                    if ($collected > 0) {
                        $collectedResources[$resourceType] += $collected;
                        $totalCollected += $collected;
                    }
                }
            }
        }
        
        $stmt->close();
        
        return [
            'success' => true,
            'total_collected' => $totalCollected,
            'collected_resources' => $collectedResources
        ];
    }
    
    /**
     * 收集所有用户的资源点资源
     * @return array 收集结果
     */
    public function collectResourcesForAll() {
        // 获取所有用户
        $query = "SELECT user_id FROM users";
        $result = $this->db->query($query);
        
        $collectionResults = [];
        $totalUsers = 0;
        $successfulUsers = 0;
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $userId = $row['user_id'];
                $totalUsers++;
                
                $userResult = $this->collectResourcesForUser($userId);
                if ($userResult['success']) {
                    $successfulUsers++;
                    $collectionResults[] = [
                        'user_id' => $userId,
                        'total_collected' => $userResult['total_collected'],
                        'collected_resources' => $userResult['collected_resources']
                    ];
                }
            }
        }
        
        return [
            'success' => true,
            'total_users' => $totalUsers,
            'successful_users' => $successfulUsers,
            'collection_results' => $collectionResults
        ];
    }
    
    /**
     * 收集指定资源点的资源
     * @param int $tileId 资源点ID
     * @param int $userId 用户ID
     * @return array 收集结果
     */
    public function collectResourceFromTile($tileId, $userId) {
        // 获取资源点信息
        $tile = new Map($tileId);
        if (!$tile->isValid() || $tile->getType() != 'resource' || $tile->getOwnerId() != $userId) {
            return [
                'success' => false,
                'message' => '资源点无效或不属于该用户'
            ];
        }
        
        // 收集资源
        $collected = $tile->collectResource($userId);
        
        if ($collected === false) {
            return [
                'success' => false,
                'message' => '收集资源失败'
            ];
        }
        
        return [
            'success' => true,
            'collected' => $collected,
            'resource_type' => $tile->getSubtype(),
            'remaining' => $tile->getResourceAmount()
        ];
    }
}

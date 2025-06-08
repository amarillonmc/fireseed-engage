<?php
// 种火集结号 - 管理员管理类

class AdminManager {
    private $db;
    private $currentAdmin;
    
    /**
     * 管理员权限等级定义
     */
    const ADMIN_LEVELS = [
        0 => '普通用户',
        1 => '初级管理员',    // 查看基础信息
        2 => '客服管理员',    // 处理用户问题
        3 => '内容管理员',    // 管理游戏内容
        4 => '数据管理员',    // 修改用户数据
        5 => '系统管理员',    // 系统配置
        6 => '高级管理员',    // 高级功能
        7 => '主管理员',      // 几乎所有权限
        8 => '副超管',        // 超级管理员助手
        9 => '超级管理员'     // 所有权限
    ];
    
    /**
     * 权限功能映射
     */
    const PERMISSIONS = [
        'view_users' => 1,           // 查看用户列表
        'view_game_data' => 1,       // 查看游戏数据
        'view_logs' => 2,            // 查看日志
        'edit_user_basic' => 3,      // 编辑用户基础信息
        'edit_user_resources' => 4,  // 编辑用户资源
        'edit_user_cities' => 4,     // 编辑用户城池
        'edit_user_generals' => 4,   // 编辑用户武将
        'edit_user_armies' => 4,     // 编辑用户军队
        'edit_game_config' => 5,     // 编辑游戏配置
        'manage_map' => 5,           // 管理地图
        'reset_game' => 6,           // 重置游戏
        'manage_admins' => 7,        // 管理管理员
        'system_maintenance' => 8,   // 系统维护
        'full_access' => 9           // 完全访问权限
    ];
    
    /**
     * 构造函数
     * @param User $admin 当前管理员用户
     */
    public function __construct($admin) {
        $this->db = Database::getInstance()->getConnection();
        $this->currentAdmin = $admin;
    }
    
    /**
     * 检查权限
     * @param string $permission 权限名称
     * @return bool
     */
    public function hasPermission($permission) {
        if (!$this->currentAdmin->isAdmin()) {
            return false;
        }
        
        if (!isset(self::PERMISSIONS[$permission])) {
            return false;
        }
        
        $requiredLevel = self::PERMISSIONS[$permission];
        return $this->currentAdmin->hasAdminLevel($requiredLevel);
    }
    
    /**
     * 获取管理员等级名称
     * @param int $level 等级
     * @return string
     */
    public static function getAdminLevelName($level) {
        return self::ADMIN_LEVELS[$level] ?? '未知等级';
    }
    
    /**
     * 获取所有权限列表
     * @return array
     */
    public static function getAllPermissions() {
        return self::PERMISSIONS;
    }
    
    /**
     * 获取用户可用的权限
     * @param int $adminLevel 管理员等级
     * @return array
     */
    public static function getAvailablePermissions($adminLevel) {
        $permissions = [];
        foreach (self::PERMISSIONS as $permission => $requiredLevel) {
            if ($adminLevel >= $requiredLevel) {
                $permissions[] = $permission;
            }
        }
        return $permissions;
    }
    
    /**
     * 修改用户资源
     * @param int $userId 用户ID
     * @param array $resources 资源数组
     * @return bool
     */
    public function updateUserResources($userId, $resources) {
        if (!$this->hasPermission('edit_user_resources')) {
            return false;
        }
        
        $resource = new Resource($userId);
        if (!$resource->isValid()) {
            return false;
        }
        
        $validResources = ['bright_crystal', 'warm_crystal', 'cold_crystal', 'green_crystal', 'day_crystal', 'night_crystal'];
        $updateFields = [];
        $updateValues = [];
        $types = '';
        
        foreach ($resources as $type => $amount) {
            if (in_array($type, $validResources) && is_numeric($amount) && $amount >= 0) {
                $updateFields[] = "$type = ?";
                $updateValues[] = intval($amount);
                $types .= 'i';
            }
        }
        
        if (empty($updateFields)) {
            return false;
        }
        
        $updateValues[] = $userId;
        $types .= 'i';
        
        $query = "UPDATE resources SET " . implode(', ', $updateFields) . " WHERE user_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param($types, ...$updateValues);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            $this->currentAdmin->logAdminAction('update_user_resources', 'user', $userId, json_encode($resources));
        }
        
        return $result;
    }
    
    /**
     * 修改用户等级
     * @param int $userId 用户ID
     * @param int $level 新等级
     * @return bool
     */
    public function updateUserLevel($userId, $level) {
        if (!$this->hasPermission('edit_user_basic')) {
            return false;
        }
        
        if ($level < 1 || $level > 100) {
            return false;
        }
        
        $query = "UPDATE users SET level = ? WHERE user_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $level, $userId);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            $this->currentAdmin->logAdminAction('update_user_level', 'user', $userId, "New level: $level");
        }
        
        return $result;
    }
    
    /**
     * 修改用户思考回路
     * @param int $userId 用户ID
     * @param int $circuitPoints 思考回路点数
     * @param int $maxCircuitPoints 最大思考回路点数
     * @return bool
     */
    public function updateUserCircuitPoints($userId, $circuitPoints, $maxCircuitPoints) {
        if (!$this->hasPermission('edit_user_basic')) {
            return false;
        }
        
        if ($circuitPoints < 0 || $maxCircuitPoints < 1 || $circuitPoints > $maxCircuitPoints) {
            return false;
        }
        
        $query = "UPDATE users SET circuit_points = ?, max_circuit_points = ? WHERE user_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('iii', $circuitPoints, $maxCircuitPoints, $userId);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            $this->currentAdmin->logAdminAction('update_user_circuit_points', 'user', $userId, 
                "Circuit: $circuitPoints/$maxCircuitPoints");
        }
        
        return $result;
    }
    
    /**
     * 删除用户城池
     * @param int $cityId 城池ID
     * @return bool
     */
    public function deleteUserCity($cityId) {
        if (!$this->hasPermission('edit_user_cities')) {
            return false;
        }
        
        $city = new City($cityId);
        if (!$city->isValid()) {
            return false;
        }
        
        $userId = $city->getOwnerId();
        
        $query = "DELETE FROM cities WHERE city_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $cityId);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            $this->currentAdmin->logAdminAction('delete_user_city', 'city', $cityId, "User ID: $userId");
        }
        
        return $result;
    }
    
    /**
     * 删除用户武将
     * @param int $generalId 武将ID
     * @return bool
     */
    public function deleteUserGeneral($generalId) {
        if (!$this->hasPermission('edit_user_generals')) {
            return false;
        }
        
        $general = new General($generalId);
        if (!$general->isValid()) {
            return false;
        }
        
        $userId = $general->getOwnerId();
        
        $query = "DELETE FROM generals WHERE general_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $generalId);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            $this->currentAdmin->logAdminAction('delete_user_general', 'general', $generalId, "User ID: $userId");
        }
        
        return $result;
    }
    
    /**
     * 设置用户管理员等级
     * @param int $userId 用户ID
     * @param int $adminLevel 管理员等级
     * @return bool
     */
    public function setUserAdminLevel($userId, $adminLevel) {
        if (!$this->hasPermission('manage_admins')) {
            return false;
        }
        
        // 不能设置比自己更高的等级
        if ($adminLevel > $this->currentAdmin->getAdminLevel()) {
            return false;
        }
        
        $user = new User($userId);
        if (!$user->isValid()) {
            return false;
        }
        
        $result = $user->setAdminLevel($adminLevel);
        
        if ($result) {
            $this->currentAdmin->logAdminAction('set_admin_level', 'user', $userId, 
                "Admin level: $adminLevel (" . self::getAdminLevelName($adminLevel) . ")");
        }
        
        return $result;
    }
    
    /**
     * 获取管理员操作日志
     * @param int $limit 限制数量
     * @param int $offset 偏移量
     * @param int $adminId 管理员ID（可选）
     * @return array
     */
    public function getAdminLogs($limit = 50, $offset = 0, $adminId = null) {
        if (!$this->hasPermission('view_logs')) {
            return [];
        }
        
        $query = "SELECT al.*, u.username as admin_username 
                  FROM admin_logs al 
                  LEFT JOIN users u ON al.admin_id = u.user_id";
        
        $params = [];
        $types = '';
        
        if ($adminId) {
            $query .= " WHERE al.admin_id = ?";
            $params[] = $adminId;
            $types .= 'i';
        }
        
        $query .= " ORDER BY al.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';
        
        $stmt = $this->db->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $logs = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $logs[] = $row;
            }
        }
        
        $stmt->close();
        return $logs;
    }
}

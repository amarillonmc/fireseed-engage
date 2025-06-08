<?php
// 种火集结号 - 用户类

class User {
    private $db;
    private $userId;
    private $username;
    private $email;
    private $level;
    private $circuitPoints;
    private $maxCircuitPoints;
    private $maxGeneralCost;
    private $adminLevel;
    private $lastLogin;
    private $createdAt;
    private $isValid = false;
    
    /**
     * 构造函数
     * @param int $userId 用户ID
     */
    public function __construct($userId = null) {
        $this->db = Database::getInstance()->getConnection();
        
        if ($userId !== null) {
            $this->userId = $userId;
            $this->loadUserData();
        }
    }
    
    /**
     * 加载用户数据
     */
    private function loadUserData() {
        $query = "SELECT * FROM users WHERE user_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->userId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            $userData = $result->fetch_assoc();
            $this->username = $userData['username'];
            $this->email = $userData['email'];
            $this->level = $userData['level'];
            $this->circuitPoints = $userData['circuit_points'];
            $this->maxCircuitPoints = $userData['max_circuit_points'];
            $this->maxGeneralCost = $userData['max_general_cost'];
            $this->adminLevel = $userData['admin_level'] ?? 0;
            $this->lastLogin = $userData['last_login'];
            $this->createdAt = $userData['registration_date'];
            $this->isValid = true;
        }

        $stmt->close();
    }
    
    /**
     * 检查用户是否有效
     * @return bool
     */
    public function isValid() {
        return $this->isValid;
    }
    
    /**
     * 创建新用户
     * @param string $username 用户名
     * @param string $password 密码
     * @param string $email 邮箱
     * @return bool|int 成功返回用户ID，失败返回false
     */
    public function createUser($username, $password, $email) {
        // 检查用户名是否已存在
        $query = "SELECT user_id FROM users WHERE username = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $stmt->close();
            return false; // 用户名已存在
        }
        
        $stmt->close();
        
        // 检查邮箱是否已存在
        $query = "SELECT user_id FROM users WHERE email = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $stmt->close();
            return false; // 邮箱已存在
        }
        
        $stmt->close();
        
        // 创建新用户
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $registrationDate = date('Y-m-d H:i:s');
        
        $query = "INSERT INTO users (username, password, email, registration_date, level, circuit_points, max_circuit_points, max_general_cost, admin_level)
                  VALUES (?, ?, ?, ?, 1, 1, 10, 10.0, 0)";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ssss', $username, $hashedPassword, $email, $registrationDate);
        $result = $stmt->execute();
        
        if ($result) {
            $userId = $this->db->insert_id;
            $stmt->close();
            
            // 初始化用户资源
            $resourceQuery = "INSERT INTO resources (user_id, bright_crystal, warm_crystal, cold_crystal, green_crystal, day_crystal, night_crystal, last_update) 
                             VALUES (?, 1000, 1000, 1000, 1000, 1000, 1000, ?)";
            $resourceStmt = $this->db->prepare($resourceQuery);
            $resourceStmt->bind_param('is', $userId, $registrationDate);
            $resourceStmt->execute();
            $resourceStmt->close();
            
            // 设置用户数据
            $this->userId = $userId;
            $this->username = $username;
            $this->email = $email;
            $this->level = 1;
            $this->circuitPoints = 1;
            $this->maxCircuitPoints = 10;
            $this->maxGeneralCost = 10.0;
            $this->isValid = true;
            
            return $userId;
        }
        
        $stmt->close();
        return false;
    }
    
    /**
     * 验证用户登录
     * @param string $username 用户名
     * @param string $password 密码
     * @return bool|int 成功返回用户ID，失败返回false
     */
    public function login($username, $password) {
        $query = "SELECT user_id, password FROM users WHERE username = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $userData = $result->fetch_assoc();
            $stmt->close();
            
            if (password_verify($password, $userData['password'])) {
                // 更新最后登录时间
                $lastLogin = date('Y-m-d H:i:s');
                $updateQuery = "UPDATE users SET last_login = ? WHERE user_id = ?";
                $updateStmt = $this->db->prepare($updateQuery);
                $updateStmt->bind_param('si', $lastLogin, $userData['user_id']);
                $updateStmt->execute();
                $updateStmt->close();
                
                // 设置用户数据
                $this->userId = $userData['user_id'];
                $this->loadUserData();
                
                return $this->userId;
            }
        } else {
            $stmt->close();
        }
        
        return false;
    }
    
    /**
     * 获取用户ID
     * @return int
     */
    public function getUserId() {
        return $this->userId;
    }
    
    /**
     * 获取用户名
     * @return string
     */
    public function getUsername() {
        return $this->username;
    }
    
    /**
     * 获取用户邮箱
     * @return string
     */
    public function getEmail() {
        return $this->email;
    }
    
    /**
     * 获取用户等级
     * @return int
     */
    public function getLevel() {
        return $this->level;
    }
    
    /**
     * 获取思考回路点数
     * @return int
     */
    public function getCircuitPoints() {
        return $this->circuitPoints;
    }
    
    /**
     * 获取最大思考回路点数
     * @return int
     */
    public function getMaxCircuitPoints() {
        return $this->maxCircuitPoints;
    }
    
    /**
     * 获取最大武将费用
     * @return float
     */
    public function getMaxGeneralCost() {
        return $this->maxGeneralCost;
    }

    /**
     * 获取管理员等级
     * @return int
     */
    public function getAdminLevel() {
        return $this->adminLevel;
    }

    /**
     * 获取最后登录时间
     * @return string
     */
    public function getLastLogin() {
        return $this->lastLogin;
    }

    /**
     * 获取创建时间
     * @return string
     */
    public function getCreatedAt() {
        return $this->createdAt;
    }

    /**
     * 检查是否为管理员
     * @return bool
     */
    public function isAdmin() {
        return $this->adminLevel > 0;
    }

    /**
     * 检查是否为超级管理员
     * @return bool
     */
    public function isSuperAdmin() {
        return $this->adminLevel >= 9;
    }

    /**
     * 检查管理员权限等级
     * @param int $requiredLevel 需要的权限等级
     * @return bool
     */
    public function hasAdminLevel($requiredLevel) {
        return $this->adminLevel >= $requiredLevel;
    }
    
    /**
     * 增加思考回路点数
     * @param int $points 增加的点数
     * @return bool
     */
    public function addCircuitPoints($points) {
        if ($points <= 0) {
            return false;
        }
        
        $newPoints = $this->circuitPoints + $points;
        if ($newPoints > $this->maxCircuitPoints) {
            $newPoints = $this->maxCircuitPoints;
        }
        
        $query = "UPDATE users SET circuit_points = ? WHERE user_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $newPoints, $this->userId);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            $this->circuitPoints = $newPoints;
            return true;
        }
        
        return false;
    }
    
    /**
     * 减少思考回路点数
     * @param int $points 减少的点数
     * @return bool
     */
    public function reduceCircuitPoints($points) {
        if ($points <= 0 || $points > $this->circuitPoints) {
            return false;
        }
        
        $newPoints = $this->circuitPoints - $points;
        
        $query = "UPDATE users SET circuit_points = ? WHERE user_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $newPoints, $this->userId);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            $this->circuitPoints = $newPoints;
            return true;
        }
        
        return false;
    }
    
    /**
     * 增加用户等级
     * @param int $levels 增加的等级数
     * @return bool
     */
    public function addLevel($levels) {
        if ($levels <= 0) {
            return false;
        }
        
        $newLevel = $this->level + $levels;
        $newMaxCircuitPoints = $this->maxCircuitPoints + ($levels * 2); // 每升一级增加2点最大思考回路
        $newMaxGeneralCost = $this->maxGeneralCost + ($levels * 0.5); // 每升一级增加0.5点最大武将费用
        
        $query = "UPDATE users SET level = ?, max_circuit_points = ?, max_general_cost = ? WHERE user_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('iddi', $newLevel, $newMaxCircuitPoints, $newMaxGeneralCost, $this->userId);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            $this->level = $newLevel;
            $this->maxCircuitPoints = $newMaxCircuitPoints;
            $this->maxGeneralCost = $newMaxGeneralCost;
            return true;
        }
        
        return false;
    }

    /**
     * 设置用户管理员等级
     * @param int $adminLevel 管理员等级 (0=普通用户, 1-8=不同等级管理员, 9=超级管理员)
     * @return bool
     */
    public function setAdminLevel($adminLevel) {
        if ($adminLevel < 0 || $adminLevel > 9) {
            return false;
        }

        $query = "UPDATE users SET admin_level = ? WHERE user_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $adminLevel, $this->userId);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            $this->adminLevel = $adminLevel;
            return true;
        }

        return false;
    }

    /**
     * 记录管理员操作日志
     * @param string $action 操作类型
     * @param string $targetType 目标类型
     * @param int $targetId 目标ID
     * @param string $details 详细信息
     * @return bool
     */
    public function logAdminAction($action, $targetType = null, $targetId = null, $details = null) {
        if (!$this->isAdmin()) {
            return false;
        }

        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $query = "INSERT INTO admin_logs (admin_id, action, target_type, target_id, details, ip_address, user_agent)
                  VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('issiiss', $this->userId, $action, $targetType, $targetId, $details, $ipAddress, $userAgent);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * 获取所有用户列表（管理员功能）
     * @param int $limit 限制数量
     * @param int $offset 偏移量
     * @return array
     */
    public static function getAllUsers($limit = 50, $offset = 0) {
        $db = Database::getInstance()->getConnection();
        $query = "SELECT user_id, username, email, level, admin_level, registration_date, last_login
                  FROM users ORDER BY user_id DESC LIMIT ? OFFSET ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param('ii', $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $users = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
        }

        $stmt->close();
        return $users;
    }

    /**
     * 搜索用户（管理员功能）
     * @param string $keyword 搜索关键词
     * @param int $limit 限制数量
     * @return array
     */
    public static function searchUsers($keyword, $limit = 50) {
        $db = Database::getInstance()->getConnection();
        $searchTerm = '%' . $keyword . '%';
        $query = "SELECT user_id, username, email, level, admin_level, registration_date, last_login
                  FROM users
                  WHERE username LIKE ? OR email LIKE ?
                  ORDER BY user_id DESC LIMIT ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param('ssi', $searchTerm, $searchTerm, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $users = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
        }

        $stmt->close();
        return $users;
    }

    /**
     * 获取用户总数
     * @return int
     */
    public static function getTotalUserCount() {
        $db = Database::getInstance()->getConnection();
        $query = "SELECT COUNT(*) as total FROM users";
        $result = $db->query($query);
        $row = $result->fetch_assoc();
        return $row['total'];
    }

    /**
     * 创建管理员用户（安装时使用）
     * @param string $username 用户名
     * @param string $password 密码
     * @param string $email 邮箱
     * @param int $adminLevel 管理员等级
     * @return bool|int 成功返回用户ID，失败返回false
     */
    public static function createAdminUser($username, $password, $email, $adminLevel = 9) {
        $user = new User();
        $userId = $user->createUser($username, $password, $email);

        if ($userId) {
            $user->setAdminLevel($adminLevel);
            return $userId;
        }

        return false;
    }
}

<?php
// 种火集结号 - 武将分配类 / Fireseed Engage - general assignment class

class GeneralAssignment {
    private $db;
    private $assignmentId;
    private $generalId;
    private $assignmentType;
    private $targetId;
    private $assignedAt;
    private $isValid = false;
    
    /**
     * 构造函数
     * @param int $assignmentId 分配ID
     */
    public function __construct($assignmentId = null) {
        $this->db = Database::getInstance()->getConnection();
        
        if ($assignmentId !== null) {
            $this->assignmentId = $assignmentId;
            $this->loadAssignmentData();
        }
    }
    
    /**
     * 加载分配数据
     */
    private function loadAssignmentData() {
        $query = "SELECT * FROM general_assignments WHERE assignment_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->assignmentId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $assignmentData = $result->fetch_assoc();
            $this->generalId = $assignmentData['general_id'];
            $this->assignmentType = $assignmentData['assignment_type'];
            $this->targetId = $assignmentData['target_id'];
            $this->assignedAt = $assignmentData['assigned_at'];
            $this->isValid = true;
        }
        
        $stmt->close();
    }
    
    /**
     * 创建新分配 / Create an assignment
     * @param int $generalId 武将ID / General ID
     * @param string $assignmentType 分配类型 / Assignment type
     * @param int $targetId 目标ID / Target ID
     * @return bool|int 成功返回分配ID，失败返回false / Assignment ID on success, false otherwise
     */
    public function createAssignment($generalId, $assignmentType, $targetId) {
        // 检查参数 / Validate arguments
        if (empty($generalId) || empty($assignmentType) || empty($targetId)) {
            return false;
        }
        
        // 检查分配类型是否有效 / Validate assignment type
        $validTypes = ['city', 'army'];
        if (!in_array($assignmentType, $validTypes, true)) {
            return false;
        }

        // 兼容入口也必须走武将类的事务、归属与编制上限校验 / The compatibility entry point must use General's transaction, ownership, and roster-limit checks
        $general = new General((int) $generalId);
        if (!$general->isValid()
            || !$general->assignGeneral($assignmentType, (int) $targetId)) {
            return false;
        }

        $assignment = self::getGeneralAssignment((int) $generalId);
        if (!$assignment || !$assignment->isValid()) {
            return false;
        }

        // 同类实例可安全复制已验证的私有状态 / Instances of this class may copy validated private state
        $this->assignmentId = $assignment->assignmentId;
        $this->generalId = $assignment->generalId;
        $this->assignmentType = $assignment->assignmentType;
        $this->targetId = $assignment->targetId;
        $this->assignedAt = $assignment->assignedAt;
        $this->isValid = true;
        
        return $this->assignmentId;
    }
    
    /**
     * 取消分配
     * @return bool 是否成功
     */
    public function cancelAssignment() {
        if (!$this->isValid) {
            return false;
        }
        
        $query = "DELETE FROM general_assignments WHERE assignment_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('i', $this->assignmentId);
        $result = $stmt->execute();
        $stmt->close();
        
        if ($result) {
            $this->isValid = false;
            return true;
        }
        
        return false;
    }
    
    /**
     * 获取分配ID
     * @return int
     */
    public function getAssignmentId() {
        return $this->assignmentId;
    }
    
    /**
     * 获取武将ID
     * @return int
     */
    public function getGeneralId() {
        return $this->generalId;
    }
    
    /**
     * 获取分配类型
     * @return string
     */
    public function getAssignmentType() {
        return $this->assignmentType;
    }
    
    /**
     * 获取目标ID
     * @return int
     */
    public function getTargetId() {
        return $this->targetId;
    }
    
    /**
     * 获取分配时间
     * @return string
     */
    public function getAssignedAt() {
        return $this->assignedAt;
    }
    
    /**
     * 检查分配是否有效
     * @return bool
     */
    public function isValid() {
        return $this->isValid;
    }
    
    /**
     * 获取武将的分配信息
     * @param int $generalId 武将ID
     * @return GeneralAssignment|null 分配对象
     */
    public static function getGeneralAssignment($generalId) {
        $db = Database::getInstance()->getConnection();
        
        $query = "SELECT assignment_id FROM general_assignments WHERE general_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $generalId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $stmt->close();
            return new GeneralAssignment($row['assignment_id']);
        }
        
        $stmt->close();
        return null;
    }
    
    /**
     * 获取城池的所有分配
     * @param int $cityId 城池ID
     * @return array 分配数组
     */
    public static function getCityAssignments($cityId) {
        $db = Database::getInstance()->getConnection();
        
        $query = "SELECT assignment_id FROM general_assignments WHERE assignment_type = 'city' AND target_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $cityId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $assignments = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $assignment = new GeneralAssignment($row['assignment_id']);
                if ($assignment->isValid()) {
                    $assignments[] = $assignment;
                }
            }
        }
        
        $stmt->close();
        return $assignments;
    }
    
    /**
     * 获取军队的所有分配
     * @param int $armyId 军队ID
     * @return array 分配数组
     */
    public static function getArmyAssignments($armyId) {
        $db = Database::getInstance()->getConnection();
        
        $query = "SELECT assignment_id FROM general_assignments WHERE assignment_type = 'army' AND target_id = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param('i', $armyId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $assignments = [];
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $assignment = new GeneralAssignment($row['assignment_id']);
                if ($assignment->isValid()) {
                    $assignments[] = $assignment;
                }
            }
        }
        
        $stmt->close();
        return $assignments;
    }
}

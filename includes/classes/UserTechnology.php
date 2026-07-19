<?php
// 种火集结号 - 用户科技类

class UserTechnology {
    private $db;
    private $userId;
    private $techId;
    private $level;
    private $researchTime;
    private $isValid = false;
    
    /**
     * 构造函数
     * @param int $userId 用户ID
     * @param int $techId 科技ID
     */
    public function __construct($userId, $techId) {
        $this->db = Database::getInstance()->getConnection();
        $this->userId = $userId;
        $this->techId = $techId;
        $this->loadUserTechnologyData();
    }
    
    /**
     * 加载用户科技数据
     */
    private function loadUserTechnologyData() {
        $query = "SELECT * FROM user_technologies WHERE user_id = ? AND tech_id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bind_param('ii', $this->userId, $this->techId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            $data = $result->fetch_assoc();
            $this->level = $data['level'];
            $this->researchTime = $data['research_time'];
            $this->isValid = true;
        } else {
            // 如果没有记录，创建一个等级为0的记录
            $this->level = 0;
            $this->researchTime = null;
            $this->isValid = false;
        }
        
        $stmt->close();
    }
    
    /**
     * 检查用户科技是否有效
     * @return bool
     */
    public function isValid() {
        return $this->isValid;
    }
    
    /**
     * 获取用户ID
     * @return int
     */
    public function getUserId() {
        return $this->userId;
    }
    
    /**
     * 获取科技ID
     * @return int
     */
    public function getTechId() {
        return $this->techId;
    }
    
    /**
     * 获取科技等级
     * @return int
     */
    public function getLevel() {
        return $this->level;
    }
    
    /**
     * 获取研究完成时间
     * @return string|null
     */
    public function getResearchTime() {
        return $this->researchTime;
    }
    
    /**
     * 检查是否正在研究
     * @return bool
     */
    public function isResearching() {
        if (!$this->researchTime) {
            return false;
        }
        
        $researchTime = strtotime($this->researchTime);
        $now = time();
        
        return $now < $researchTime;
    }
    
    /**
     * 原子化开始科技研究 / Start technology research atomically
     * @param int $cityId 城池ID（用于检查研究所等级） / City ID used to validate the research lab
     * @return bool 是否成功 / Whether research started
     */
    public function startResearch($cityId) {
        $cityId = (int) $cityId;
        if ($cityId <= 0 || (int) $this->userId <= 0 || (int) $this->techId <= 0) {
            return false;
        }

        $technology = new Technology($this->techId);
        if (!$technology->isValid() || !$technology->hasValidCostPolicy()) {
            return false;
        }

        $this->db->begin_transaction();
        try {
            lockSeasonForWorldAction($this->db);

            // 先锁城池与可用研究所，确保所有权和设施等级不会在扣费时变化 / Lock the city and usable lab before charging so ownership and level stay authoritative
            $query = "SELECT owner_id
                      FROM cities
                      WHERE city_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $cityId);
            $stmt->execute();
            $result = $stmt->get_result();
            $city = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$city || (int) $city['owner_id'] !== (int) $this->userId) {
                throw new DomainException('城池不存在或不属于当前玩家');
            }

            $query = "SELECT facility_id, level
                      FROM facilities
                      WHERE city_id = ? AND type = 'research_lab'
                        AND construction_time IS NULL
                        AND upgrade_time IS NULL
                      ORDER BY level DESC, facility_id
                      LIMIT 1
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $cityId);
            $stmt->execute();
            $result = $stmt->get_result();
            $researchLab = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$researchLab) {
                throw new DomainException('没有可用的研究所');
            }

            // 锁内重读等级与队列，避免重复研究或使用旧等级价格 / Reload level and queue under lock to prevent duplicate research or stale pricing
            $query = "SELECT user_tech.level,
                             user_tech.research_time,
                             technology.max_level
                      FROM user_technologies AS user_tech
                      INNER JOIN technologies AS technology
                        ON technology.tech_id = user_tech.tech_id
                      WHERE user_tech.user_id = ?
                        AND user_tech.tech_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $userId = (int) $this->userId;
            $techId = (int) $this->techId;
            $stmt->bind_param('ii', $userId, $techId);
            $stmt->execute();
            $result = $stmt->get_result();
            $userTechnology = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            $currentLevel = $userTechnology
                ? max(0, (int) $userTechnology['level'])
                : 0;
            if ($userTechnology && $userTechnology['research_time'] !== null) {
                throw new DomainException('该科技正在研究中');
            }
            if ($currentLevel >= (int) $technology->getMaxLevel()) {
                throw new DomainException('该科技已经达到最高等级');
            }
            if ((int) $researchLab['level'] < $currentLevel + 1) {
                throw new DomainException('研究所等级不足');
            }

            $resourceColumns = [
                'bright' => 'bright_crystal',
                'warm' => 'warm_crystal',
                'cold' => 'cold_crystal',
                'green' => 'green_crystal',
                'day' => 'day_crystal',
                'night' => 'night_crystal'
            ];
            $upgradeCost = $technology->getUpgradeCostAtLevel($currentLevel);
            $costs = array_fill_keys(array_keys($resourceColumns), 0);
            foreach ($upgradeCost as $resourceType => $cost) {
                if (!isset($resourceColumns[$resourceType])
                    || !is_numeric($cost)
                    || (int) $cost < 0) {
                    throw new RuntimeException('科技研究成本无效 / Invalid research cost');
                }
                $costs[$resourceType] = (int) $cost;
            }

            $query = "SELECT bright_crystal, warm_crystal, cold_crystal,
                             green_crystal, day_crystal, night_crystal
                      FROM resources
                      WHERE user_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $resources = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$resources) {
                throw new RuntimeException('玩家资源记录不存在 / Resource wallet is missing');
            }
            foreach ($costs as $resourceType => $cost) {
                $column = $resourceColumns[$resourceType];
                if ((int) $resources[$column] < $cost) {
                    throw new DomainException(getResourceName($resourceType) . '不足');
                }
            }

            $query = "UPDATE resources
                      SET bright_crystal = bright_crystal - ?,
                          warm_crystal = warm_crystal - ?,
                          cold_crystal = cold_crystal - ?,
                          green_crystal = green_crystal - ?,
                          day_crystal = day_crystal - ?,
                          night_crystal = night_crystal - ?
                      WHERE user_id = ?";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param(
                'iiiiiii',
                $costs['bright'],
                $costs['warm'],
                $costs['cold'],
                $costs['green'],
                $costs['day'],
                $costs['night'],
                $userId
            );
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('扣除研究资源失败 / Failed to charge research resources');
            }
            $stmt->close();

            // 研究速度倍率大于一时缩短耗时，小于一时延长 / A multiplier above one shortens research and one below one lengthens it
            $speedMultiplier = max(
                0.1,
                min(10.0, (float) GameConfig::get('research_speed_multiplier', 1.0))
            );
            $baseDuration = 60 * ($currentLevel + 1);
            $researchDuration = max(
                1,
                (int) ceil($baseDuration / $speedMultiplier)
            );
            $researchTime = date('Y-m-d H:i:s', time() + $researchDuration);

            if ($userTechnology) {
                $query = "UPDATE user_technologies
                          SET research_time = ?
                          WHERE user_id = ? AND tech_id = ?
                            AND research_time IS NULL";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('sii', $researchTime, $userId, $techId);
            } else {
                $query = "INSERT INTO user_technologies
                            (user_id, tech_id, level, research_time)
                          VALUES (?, ?, 0, ?)";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('iis', $userId, $techId, $researchTime);
            }
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                $stmt->close();
                throw new RuntimeException('建立研究队列失败 / Failed to schedule research');
            }
            $stmt->close();

            $this->db->commit();
            $this->level = $currentLevel;
            $this->researchTime = $researchTime;
            $this->isValid = true;
            return true;
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log('Technology research start failed: ' . $exception->getMessage());
            return false;
        }
    }
    
    /**
     * 原子化完成到期研究 / Complete due research atomically
     * @return bool 是否完成 / Whether research completed
     */
    public function completeResearch() {
        if (!$this->researchTime) {
            return false;
        }
        
        $researchTime = strtotime($this->researchTime);
        $now = time();
        
        // 检查研究是否已完成
        if ($now < $researchTime) {
            return false;
        }
        
        $this->db->begin_transaction();
        try {
            lockSeasonForWorldAction($this->db);

            $query = "SELECT user_tech.level,
                             user_tech.research_time,
                             technology.max_level
                      FROM user_technologies AS user_tech
                      INNER JOIN technologies AS technology
                        ON technology.tech_id = user_tech.tech_id
                      WHERE user_tech.user_id = ?
                        AND user_tech.tech_id = ?
                      FOR UPDATE";
            $stmt = $this->db->prepare($query);
            $userId = (int) $this->userId;
            $techId = (int) $this->techId;
            $stmt->bind_param('ii', $userId, $techId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$row
                || $row['research_time'] === null
                || strtotime($row['research_time']) > time()) {
                $this->db->rollback();
                return false;
            }

            $currentLevel = max(0, (int) $row['level']);
            $maxLevel = max(0, (int) $row['max_level']);
            if ($currentLevel >= $maxLevel) {
                // 运营调整上限后取消已经失效的旧队列。 / Cancel a stale queue when an operator lowers the technology cap.
                $query = "UPDATE user_technologies
                          SET research_time = NULL
                          WHERE user_id = ? AND tech_id = ?
                            AND research_time IS NOT NULL";
                $stmt = $this->db->prepare($query);
                $stmt->bind_param('ii', $userId, $techId);
                $cleared = $stmt->execute()
                    && $stmt->affected_rows === 1;
                $stmt->close();
                if (!$cleared) {
                    throw new RuntimeException(
                        '无法清除失效研究队列 / Failed to clear stale research'
                    );
                }

                $this->db->commit();
                $this->level = $currentLevel;
                $this->researchTime = null;
                return false;
            }

            $newLevel = min($maxLevel, $currentLevel + 1);
            $query = "UPDATE user_technologies
                      SET level = ?, research_time = NULL
                      WHERE user_id = ? AND tech_id = ?
                        AND research_time IS NOT NULL
                        AND research_time <= NOW()";
            $stmt = $this->db->prepare($query);
            $stmt->bind_param('iii', $newLevel, $userId, $techId);
            $completed = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            if (!$completed) {
                throw new RuntimeException(
                    '研究队列状态已经变化 / Research queue state changed'
                );
            }

            TechnologyEffectService::clearUserCache($userId);
            $completedTechnology = new Technology($techId);
            if ($completedTechnology->isValid()
                && $completedTechnology->getScope()
                    === TechnologyEffectService::SCOPE_PERMANENT
                && !TechnologyEffectService::synchronizePlayerLimits($userId)) {
                throw new RuntimeException(
                    '同步永久科研上限失败 / Failed to synchronize permanent research caps'
                );
            }

            $this->db->commit();
            $this->level = $newLevel;
            $this->researchTime = null;
            return true;
        } catch (Throwable $exception) {
            $this->db->rollback();
            error_log('Technology research completion failed: ' . $exception->getMessage());
            return false;
        }
    }
    
    /**
     * 获取用户的所有科技
     * @param int $userId 用户ID
     * @return array
     */
    public static function getUserTechnologies($userId) {
        $db = Database::getInstance()->getConnection();
        
        // 获取所有科技
        $technologies = Technology::getAllTechnologies();
        $userTechnologies = [];
        
        foreach ($technologies as $technology) {
            $userTech = new UserTechnology($userId, $technology->getTechId());
            $userTechnologies[] = [
                'technology' => $technology,
                'user_tech' => $userTech
            ];
        }
        
        return $userTechnologies;
    }
    
    /**
     * 获取用户指定类别的科技
     * @param int $userId 用户ID
     * @param string $category 科技类别
     * @return array
     */
    public static function getUserTechnologiesByCategory($userId, $category) {
        $technologies = Technology::getTechnologiesByCategory($category);
        $userTechnologies = [];
        
        foreach ($technologies as $technology) {
            $userTech = new UserTechnology($userId, $technology->getTechId());
            $userTechnologies[] = [
                'technology' => $technology,
                'user_tech' => $userTech
            ];
        }
        
        return $userTechnologies;
    }
    
    /**
     * 检查并完成到期研究，可限制为单个玩家 / Complete due research, optionally scoped to one user
     * @param int|null $userId 玩家ID，空值表示定时任务处理全部 / User ID, or null for cron-wide processing
     * @return array 完成的研究列表 / Completed research rows
     */
    public static function checkAndCompleteResearch($userId = null) {
        $db = Database::getInstance()->getConnection();
        $now = date('Y-m-d H:i:s');
        
        // 玩家接口不能顺带结算其他账号的研究 / A player request must not settle other accounts
        $query = "SELECT user_id, tech_id
                  FROM user_technologies
                  WHERE research_time IS NOT NULL
                    AND research_time <= ?";
        if ($userId !== null) {
            $query .= " AND user_id = ?";
        }
        $stmt = $db->prepare($query);
        if ($userId === null) {
            $stmt->bind_param('s', $now);
        } else {
            $userId = (int) $userId;
            $stmt->bind_param('si', $now, $userId);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $completedResearch = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $userTech = new UserTechnology($row['user_id'], $row['tech_id']);
                if ($userTech->completeResearch()) {
                    $technology = new Technology($row['tech_id']);
                    $completedResearch[] = [
                        'user_id' => $row['user_id'],
                        'tech_id' => $row['tech_id'],
                        'tech_name' => $technology->getName(),
                        'level' => $userTech->getLevel()
                    ];
                }
            }
        }
        
        $stmt->close();
        return $completedResearch;
    }
    
    /**
     * 获取用户科技的效果加成
     * @param int $userId 用户ID
     * @param string $category 科技类别
     * @return array 效果加成数组
     */
    public static function getUserTechnologyEffects($userId, $category = null) {
        $db = Database::getInstance()->getConnection();
        
        $query = "SELECT ut.tech_id, ut.level, t.name, t.category,
                         t.scope, t.effect_key, t.base_effect
                  FROM user_technologies ut 
                  JOIN technologies t ON ut.tech_id = t.tech_id 
                  WHERE ut.user_id = ? AND ut.level > 0";
        
        $params = [$userId];
        $types = 'i';
        
        if ($category) {
            $query .= " AND t.category = ?";
            $params[] = $category;
            $types .= 's';
        }
        
        $stmt = $db->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $effects = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $effectValue = TechnologyEffectService::calculateEffectAtLevel(
                    $row['base_effect'],
                    $row['level']
                );
                $effects[] = [
                    'tech_id' => $row['tech_id'],
                    'name' => $row['name'],
                    'category' => $row['category'],
                    'scope' => $row['scope'],
                    'effect_key' => $row['effect_key'],
                    'level' => $row['level'],
                    'effect_value' => $effectValue
                ];
            }
        }
        
        $stmt->close();
        return $effects;
    }
}

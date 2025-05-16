-- 种火集结号 - 更新武将系统

-- 备份原有表
CREATE TABLE IF NOT EXISTS `generals_backup` LIKE `generals`;
INSERT INTO `generals_backup` SELECT * FROM `generals`;

CREATE TABLE IF NOT EXISTS `general_skills_backup` LIKE `general_skills`;
INSERT INTO `general_skills_backup` SELECT * FROM `general_skills`;

CREATE TABLE IF NOT EXISTS `general_assignments_backup` LIKE `general_assignments`;
INSERT INTO `general_assignments_backup` SELECT * FROM `general_assignments`;

-- 删除原有表的外键约束
ALTER TABLE `general_skills` DROP FOREIGN KEY `general_skills_ibfk_1`;
ALTER TABLE `general_assignments` DROP FOREIGN KEY `general_assignments_ibfk_1`;

-- 删除原有表
DROP TABLE IF EXISTS `generals`;
DROP TABLE IF EXISTS `general_skills`;
DROP TABLE IF EXISTS `general_assignments`;

-- 创建新的武将表
CREATE TABLE `generals` (
  `general_id` int(11) NOT NULL AUTO_INCREMENT,
  `owner_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `source` varchar(255) DEFAULT '原创角色',
  `rarity` enum('B','A','S','SS','P') NOT NULL,
  `cost` float NOT NULL,
  `element` enum('亮晶晶','暖洋洋','冷冰冰','郁萌萌','昼闪闪','夜静静') NOT NULL,
  `level` int(11) NOT NULL DEFAULT 1,
  `hp` int(11) NOT NULL DEFAULT 100,
  `max_hp` int(11) NOT NULL DEFAULT 100,
  `attack` int(11) NOT NULL,
  `defense` int(11) NOT NULL,
  `speed` int(11) NOT NULL,
  `intelligence` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`general_id`),
  KEY `owner_id` (`owner_id`),
  CONSTRAINT `generals_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建新的武将技能表
CREATE TABLE `general_skills` (
  `skill_id` int(11) NOT NULL AUTO_INCREMENT,
  `general_id` int(11) NOT NULL,
  `skill_name` varchar(50) NOT NULL,
  `skill_type` enum('自带','装备') NOT NULL DEFAULT '自带',
  `slot` int(11) NOT NULL DEFAULT 0, -- 0表示自带技能，1-2表示额外技能槽
  `skill_level` int(11) NOT NULL DEFAULT 1,
  `skill_effect` text NOT NULL,
  PRIMARY KEY (`skill_id`),
  KEY `general_id` (`general_id`),
  CONSTRAINT `general_skills_ibfk_1` FOREIGN KEY (`general_id`) REFERENCES `generals` (`general_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 创建新的武将分配表
CREATE TABLE `general_assignments` (
  `assignment_id` int(11) NOT NULL AUTO_INCREMENT,
  `general_id` int(11) NOT NULL,
  `assignment_type` enum('city', 'army') NOT NULL,
  `target_id` int(11) NOT NULL,
  `assigned_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`assignment_id`),
  UNIQUE KEY `general_id` (`general_id`),
  KEY `target_id` (`target_id`),
  CONSTRAINT `general_assignments_ibfk_1` FOREIGN KEY (`general_id`) REFERENCES `generals` (`general_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 迁移数据（如果需要）
-- 注意：由于属性系统完全改变，这里只迁移基本信息，属性需要重新生成
-- INSERT INTO `generals` (general_id, owner_id, name, rarity, level, is_active, created_at)
-- SELECT general_id, owner_id, name, 
--   CASE 
--     WHEN rarity = 'common' THEN 'B'
--     WHEN rarity = 'uncommon' THEN 'A'
--     WHEN rarity = 'rare' THEN 'S'
--     WHEN rarity = 'epic' THEN 'SS'
--     WHEN rarity = 'legendary' THEN 'P'
--   END,
--   level, is_active, created_at
-- FROM `generals_backup`;

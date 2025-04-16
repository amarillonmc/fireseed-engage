-- 种火集结号 - 武将技能表

CREATE TABLE `general_skills` (
  `skill_id` int(11) NOT NULL AUTO_INCREMENT,
  `general_id` int(11) NOT NULL,
  `skill_type` varchar(20) NOT NULL,
  `skill_name` varchar(50) NOT NULL,
  `skill_level` int(11) NOT NULL DEFAULT 1,
  `skill_effect` text NOT NULL,
  PRIMARY KEY (`skill_id`),
  KEY `general_id` (`general_id`),
  CONSTRAINT `general_skills_ibfk_1` FOREIGN KEY (`general_id`) REFERENCES `generals` (`general_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

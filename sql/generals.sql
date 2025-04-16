-- 种火集结号 - 武将表

CREATE TABLE `generals` (
  `general_id` int(11) NOT NULL AUTO_INCREMENT,
  `owner_id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `rarity` enum('common', 'uncommon', 'rare', 'epic', 'legendary') NOT NULL,
  `level` int(11) NOT NULL DEFAULT 1,
  `experience` int(11) NOT NULL DEFAULT 0,
  `leadership` int(11) NOT NULL,
  `strength` int(11) NOT NULL,
  `intelligence` int(11) NOT NULL,
  `politics` int(11) NOT NULL,
  `charm` int(11) NOT NULL,
  `leadership_growth` float NOT NULL,
  `strength_growth` float NOT NULL,
  `intelligence_growth` float NOT NULL,
  `politics_growth` float NOT NULL,
  `charm_growth` float NOT NULL,
  `skill_points` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`general_id`),
  KEY `owner_id` (`owner_id`),
  CONSTRAINT `generals_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

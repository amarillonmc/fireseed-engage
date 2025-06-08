-- 种火集结号 - 科技表

CREATE TABLE `technologies` (
  `tech_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `category` enum('resource','soldier','city','governor') NOT NULL,
  `base_effect` float NOT NULL,
  `base_cost` text NOT NULL,
  `level_coefficient` float NOT NULL,
  `max_level` int(11) NOT NULL,
  PRIMARY KEY (`tech_id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

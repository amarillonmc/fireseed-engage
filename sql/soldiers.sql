-- 种火集结号 - 城池士兵表 / Fireseed Engage - city soldiers table

CREATE TABLE `soldiers` (
  `soldier_id` int(11) NOT NULL AUTO_INCREMENT,
  `city_id` int(11) NOT NULL,
  `type` enum('pawn','knight','rook','bishop','golem','scout') NOT NULL,
  `level` int(11) NOT NULL DEFAULT 1,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `in_training` int(11) NOT NULL DEFAULT 0,
  `training_complete_time` datetime DEFAULT NULL,
  PRIMARY KEY (`soldier_id`),
  UNIQUE KEY `city_soldier_type` (`city_id`,`type`),
  CONSTRAINT `soldiers_ibfk_1` FOREIGN KEY (`city_id`) REFERENCES `cities` (`city_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

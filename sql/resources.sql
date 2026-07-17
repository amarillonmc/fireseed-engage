-- 种火集结号 - 玩家资源表 / Fireseed Engage - player resources table

CREATE TABLE `resources` (
  `resource_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `bright_crystal` int(11) NOT NULL DEFAULT 1000,
  `warm_crystal` int(11) NOT NULL DEFAULT 1000,
  `cold_crystal` int(11) NOT NULL DEFAULT 1000,
  `green_crystal` int(11) NOT NULL DEFAULT 1000,
  `day_crystal` int(11) NOT NULL DEFAULT 1000,
  `night_crystal` int(11) NOT NULL DEFAULT 1000,
  `last_update` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`resource_id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `resources_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

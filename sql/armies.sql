-- 种火集结号 - 军队表

CREATE TABLE `armies` (
  `army_id` int(11) NOT NULL AUTO_INCREMENT,
  `owner_id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `status` enum('idle', 'marching', 'fighting', 'returning') NOT NULL DEFAULT 'idle',
  `current_x` int(11) NOT NULL,
  `current_y` int(11) NOT NULL,
  `target_x` int(11) DEFAULT NULL,
  `target_y` int(11) DEFAULT NULL,
  `departure_time` datetime DEFAULT NULL,
  `arrival_time` datetime DEFAULT NULL,
  `return_time` datetime DEFAULT NULL,
  `city_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`army_id`),
  KEY `owner_id` (`owner_id`),
  KEY `city_id` (`city_id`),
  CONSTRAINT `armies_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `armies_ibfk_2` FOREIGN KEY (`city_id`) REFERENCES `cities` (`city_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

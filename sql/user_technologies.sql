-- 种火集结号 - 用户科技表

CREATE TABLE `user_technologies` (
  `user_id` int(11) NOT NULL,
  `tech_id` int(11) NOT NULL,
  `level` int(11) NOT NULL DEFAULT 0,
  `research_time` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`, `tech_id`),
  KEY `tech_id` (`tech_id`),
  CONSTRAINT `user_technologies_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `user_technologies_ibfk_2` FOREIGN KEY (`tech_id`) REFERENCES `technologies` (`tech_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

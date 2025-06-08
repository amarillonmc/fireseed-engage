-- 种火集结号 - 用户表

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `registration_date` datetime NOT NULL,
  `last_login` datetime DEFAULT NULL,
  `level` int(11) DEFAULT 1,
  `circuit_points` int(11) DEFAULT 1,
  `max_circuit_points` int(11) DEFAULT 10,
  `max_general_cost` float DEFAULT 10.0,
  `admin_level` int(11) DEFAULT 0,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

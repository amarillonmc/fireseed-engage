-- 种火集结号 - 城池表 / Fireseed Engage - cities table

CREATE TABLE `cities` (
  `city_id` int(11) NOT NULL AUTO_INCREMENT,
  `owner_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `x` int(11) NOT NULL,
  `y` int(11) NOT NULL,
  `level` int(11) NOT NULL DEFAULT 1,
  `durability` int(11) NOT NULL DEFAULT 3000,
  `max_durability` int(11) NOT NULL DEFAULT 3000,
  `is_main_city` tinyint(1) NOT NULL DEFAULT 0,
  `main_city_owner_id` int(11) GENERATED ALWAYS AS
    (CASE WHEN `is_main_city` = 1 THEN `owner_id` ELSE NULL END) STORED,
  `defense_strategy` enum('defense','balanced','production') NOT NULL DEFAULT 'balanced',
  `last_circuit_production` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`city_id`),
  KEY `owner_id` (`owner_id`),
  UNIQUE KEY `coordinates` (`x`, `y`),
  UNIQUE KEY `uq_cities_one_main_city` (`main_city_owner_id`),
  CONSTRAINT `cities_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 种火集结号 - 战斗表 / Fireseed Engage - battles table

CREATE TABLE `battles` (
  `battle_id` int(11) NOT NULL AUTO_INCREMENT,
  `attacker_army_id` int(11) DEFAULT NULL,
  `attacker_power_snapshot` bigint(20) NOT NULL DEFAULT 0,
  `attacker_damage_reduction_snapshot` decimal(8,3) NOT NULL DEFAULT 0.000,
  `attacker_composition_snapshot` text DEFAULT NULL,
  `attacker_name_snapshot` varchar(50) DEFAULT NULL,
  `defender_army_id` int(11) DEFAULT NULL,
  `defender_city_id` int(11) DEFAULT NULL,
  `defender_tile_id` int(11) DEFAULT NULL,
  `battle_time` datetime NOT NULL,
  `result` enum('pending','attacker_win_big','attacker_win','defender_win_big','defender_win','draw') NOT NULL DEFAULT 'pending',
  `attacker_losses` text DEFAULT NULL,
  `defender_losses` text DEFAULT NULL,
  `rewards` text DEFAULT NULL,
  PRIMARY KEY (`battle_id`),
  KEY `attacker_army_id` (`attacker_army_id`),
  KEY `defender_army_id` (`defender_army_id`),
  KEY `defender_city_id` (`defender_city_id`),
  KEY `defender_tile_id` (`defender_tile_id`),
  CONSTRAINT `battles_ibfk_1` FOREIGN KEY (`attacker_army_id`) REFERENCES `armies` (`army_id`) ON DELETE SET NULL,
  CONSTRAINT `battles_ibfk_2` FOREIGN KEY (`defender_army_id`) REFERENCES `armies` (`army_id`) ON DELETE SET NULL,
  CONSTRAINT `battles_ibfk_3` FOREIGN KEY (`defender_city_id`) REFERENCES `cities` (`city_id`) ON DELETE SET NULL,
  CONSTRAINT `battles_ibfk_4` FOREIGN KEY (`defender_tile_id`) REFERENCES `map_tiles` (`tile_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

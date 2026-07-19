-- 种火集结号 - 地图格子表 / Fireseed Engage - map tiles table

CREATE TABLE `map_tiles` (
  `tile_id` int(11) NOT NULL AUTO_INCREMENT,
  `x` int(11) NOT NULL,
  `y` int(11) NOT NULL,
  `type` varchar(20) NOT NULL,
  `subtype` varchar(20) DEFAULT NULL,
  `owner_id` int(11) DEFAULT NULL,
  `resource_amount` int(11) DEFAULT NULL,
  `npc_level` int(11) DEFAULT NULL,
  `npc_garrison` bigint(20) NOT NULL DEFAULT 0,
  `npc_respawn_time` datetime DEFAULT NULL,
  `last_collection_time` datetime DEFAULT NULL,
  `collection_efficiency` int(11) NOT NULL DEFAULT 100,
  `occupation_circuit_cost` int(11) NOT NULL DEFAULT 0,
  `is_visible` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`tile_id`),
  UNIQUE KEY `x_y` (`x`, `y`),
  KEY `owner_id` (`owner_id`),
  CONSTRAINT `map_tiles_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

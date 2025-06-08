-- 种火集结号 - 设施表

CREATE TABLE `facilities` (
  `facility_id` int(11) NOT NULL AUTO_INCREMENT,
  `city_id` int(11) NOT NULL,
  `type` enum('resource_production','governor_office','barracks','research_lab','dormitory','storage','watchtower','workshop') NOT NULL,
  `subtype` varchar(20) DEFAULT NULL,
  `level` int(11) NOT NULL DEFAULT 1,
  `x_pos` int(11) NOT NULL DEFAULT 0,
  `y_pos` int(11) NOT NULL DEFAULT 0,
  `construction_time` datetime DEFAULT NULL,
  `upgrade_time` datetime DEFAULT NULL,
  PRIMARY KEY (`facility_id`),
  KEY `city_id` (`city_id`),
  UNIQUE KEY `city_position` (`city_id`, `x_pos`, `y_pos`),
  CONSTRAINT `facilities_ibfk_1` FOREIGN KEY (`city_id`) REFERENCES `cities` (`city_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 种火集结号 - 军队单位表

CREATE TABLE `army_units` (
  `army_unit_id` int(11) NOT NULL AUTO_INCREMENT,
  `army_id` int(11) NOT NULL,
  `soldier_type` varchar(20) NOT NULL,
  `level` int(11) NOT NULL DEFAULT 1,
  `quantity` int(11) NOT NULL,
  PRIMARY KEY (`army_unit_id`),
  KEY `army_id` (`army_id`),
  CONSTRAINT `army_units_ibfk_1` FOREIGN KEY (`army_id`) REFERENCES `armies` (`army_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

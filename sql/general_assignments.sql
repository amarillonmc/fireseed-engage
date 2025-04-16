-- 种火集结号 - 武将分配表

CREATE TABLE `general_assignments` (
  `assignment_id` int(11) NOT NULL AUTO_INCREMENT,
  `general_id` int(11) NOT NULL,
  `assignment_type` enum('city', 'army') NOT NULL,
  `target_id` int(11) NOT NULL,
  `assigned_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`assignment_id`),
  UNIQUE KEY `general_id` (`general_id`),
  KEY `target_id` (`target_id`),
  CONSTRAINT `general_assignments_ibfk_1` FOREIGN KEY (`general_id`) REFERENCES `generals` (`general_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

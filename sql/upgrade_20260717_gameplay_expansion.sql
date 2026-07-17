-- 种火集结号 - 2026-07-17 玩法扩展升级脚本 / Fireseed Engage - 2026-07-17 gameplay expansion upgrade
-- 执行前请备份数据库，并先选择现有游戏数据库。DDL 会自动提交。 / Back up the database and select the existing game schema first. DDL auto-commits.
-- 本文件可重复执行，不依赖 mysql 客户端专用的 SOURCE 命令。 / This file is rerunnable and does not depend on the mysql-client-only SOURCE command.

SET NAMES utf8mb4;
SET SESSION time_zone = '+08:00';

-- 补齐旧安装器可能缺少的核心表。 / Create core tables that may be absent from legacy installer runs.
CREATE TABLE IF NOT EXISTS `resources` (
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

CREATE TABLE IF NOT EXISTS `cities` (
  `city_id` int(11) NOT NULL AUTO_INCREMENT,
  `owner_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `x` int(11) NOT NULL,
  `y` int(11) NOT NULL,
  `level` int(11) NOT NULL DEFAULT 1,
  `durability` int(11) NOT NULL DEFAULT 3000,
  `max_durability` int(11) NOT NULL DEFAULT 3000,
  `is_main_city` tinyint(1) NOT NULL DEFAULT 0,
  `defense_strategy` enum('defense','balanced','production') NOT NULL DEFAULT 'balanced',
  `last_circuit_production` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`city_id`),
  KEY `owner_id` (`owner_id`),
  UNIQUE KEY `coordinates` (`x`,`y`),
  CONSTRAINT `cities_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `soldiers` (
  `soldier_id` int(11) NOT NULL AUTO_INCREMENT,
  `city_id` int(11) NOT NULL,
  `type` enum('pawn','knight','rook','bishop','golem','scout') NOT NULL,
  `level` int(11) NOT NULL DEFAULT 1,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `in_training` int(11) NOT NULL DEFAULT 0,
  `training_complete_time` datetime DEFAULT NULL,
  PRIMARY KEY (`soldier_id`),
  UNIQUE KEY `city_soldier_type` (`city_id`,`type`),
  CONSTRAINT `soldiers_ibfk_1` FOREIGN KEY (`city_id`) REFERENCES `cities` (`city_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 用 information_schema 组装条件 ALTER，避免依赖存储过程权限或客户端 DELIMITER。 / Build conditional ALTER statements through information_schema without routine privileges or client DELIMITER support.
SET @fireseed_columns = CONCAT_WS(', ',
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'resources' AND column_name = 'green_crystal'), NULL, 'ADD COLUMN `green_crystal` int(11) NOT NULL DEFAULT 1000'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'resources' AND column_name = 'day_crystal'), NULL, 'ADD COLUMN `day_crystal` int(11) NOT NULL DEFAULT 1000'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'resources' AND column_name = 'night_crystal'), NULL, 'ADD COLUMN `night_crystal` int(11) NOT NULL DEFAULT 1000'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'resources' AND column_name = 'last_update'), NULL, 'ADD COLUMN `last_update` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP')
);
SET @fireseed_ddl = IF(@fireseed_columns = '', 'DO 0', CONCAT('ALTER TABLE `resources` ', @fireseed_columns));
PREPARE fireseed_stmt FROM @fireseed_ddl;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

SET @fireseed_columns = CONCAT_WS(', ',
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cities' AND column_name = 'level'), NULL, 'ADD COLUMN `level` int(11) NOT NULL DEFAULT 1'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cities' AND column_name = 'durability'), NULL, 'ADD COLUMN `durability` int(11) NOT NULL DEFAULT 3000'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cities' AND column_name = 'max_durability'), NULL, 'ADD COLUMN `max_durability` int(11) NOT NULL DEFAULT 3000'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cities' AND column_name = 'is_main_city'), NULL, 'ADD COLUMN `is_main_city` tinyint(1) NOT NULL DEFAULT 0'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cities' AND column_name = 'defense_strategy'), NULL, 'ADD COLUMN `defense_strategy` enum(''defense'',''balanced'',''production'') NOT NULL DEFAULT ''balanced'''),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cities' AND column_name = 'last_circuit_production'), NULL, 'ADD COLUMN `last_circuit_production` datetime DEFAULT NULL'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'cities' AND column_name = 'created_at'), NULL, 'ADD COLUMN `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP')
);
SET @fireseed_ddl = IF(@fireseed_columns = '', 'DO 0', CONCAT('ALTER TABLE `cities` ', @fireseed_columns));
PREPARE fireseed_stmt FROM @fireseed_ddl;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

SET @fireseed_columns = CONCAT_WS(', ',
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'soldiers' AND column_name = 'in_training'), NULL, 'ADD COLUMN `in_training` int(11) NOT NULL DEFAULT 0'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'soldiers' AND column_name = 'training_complete_time'), NULL, 'ADD COLUMN `training_complete_time` datetime DEFAULT NULL')
);
SET @fireseed_ddl = IF(@fireseed_columns = '', 'DO 0', CONCAT('ALTER TABLE `soldiers` ', @fireseed_columns));
PREPARE fireseed_stmt FROM @fireseed_ddl;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

SET @fireseed_columns = IF(
  EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'general_skills' AND column_name = 'slot'),
  '',
  'ADD COLUMN `slot` int(11) NOT NULL DEFAULT 0'
);
SET @fireseed_ddl = IF(@fireseed_columns = '', 'DO 0', CONCAT('ALTER TABLE `general_skills` ', @fireseed_columns));
PREPARE fireseed_stmt FROM @fireseed_ddl;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

SET @fireseed_columns = CONCAT_WS(', ',
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'map_tiles' AND column_name = 'npc_level'), NULL, 'ADD COLUMN `npc_level` int(11) DEFAULT NULL'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'map_tiles' AND column_name = 'npc_garrison'), NULL, 'ADD COLUMN `npc_garrison` bigint(20) NOT NULL DEFAULT 0'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'map_tiles' AND column_name = 'npc_respawn_time'), NULL, 'ADD COLUMN `npc_respawn_time` datetime DEFAULT NULL'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'map_tiles' AND column_name = 'last_collection_time'), NULL, 'ADD COLUMN `last_collection_time` datetime DEFAULT NULL'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'map_tiles' AND column_name = 'collection_efficiency'), NULL, 'ADD COLUMN `collection_efficiency` int(11) NOT NULL DEFAULT 100')
);
SET @fireseed_ddl = IF(@fireseed_columns = '', 'DO 0', CONCAT('ALTER TABLE `map_tiles` ', @fireseed_columns));
PREPARE fireseed_stmt FROM @fireseed_ddl;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

-- 规范旧资源空值并统一字段定义。 / Normalize legacy resource nulls and align column definitions.
UPDATE `resources`
SET
  `bright_crystal` = COALESCE(`bright_crystal`, 0),
  `warm_crystal` = COALESCE(`warm_crystal`, 0),
  `cold_crystal` = COALESCE(`cold_crystal`, 0),
  `green_crystal` = COALESCE(`green_crystal`, 0),
  `day_crystal` = COALESCE(`day_crystal`, 0),
  `night_crystal` = COALESCE(`night_crystal`, 0);

ALTER TABLE `resources`
  MODIFY COLUMN `bright_crystal` int(11) NOT NULL DEFAULT 1000,
  MODIFY COLUMN `warm_crystal` int(11) NOT NULL DEFAULT 1000,
  MODIFY COLUMN `cold_crystal` int(11) NOT NULL DEFAULT 1000,
  MODIFY COLUMN `green_crystal` int(11) NOT NULL DEFAULT 1000,
  MODIFY COLUMN `day_crystal` int(11) NOT NULL DEFAULT 1000,
  MODIFY COLUMN `night_crystal` int(11) NOT NULL DEFAULT 1000,
  MODIFY COLUMN `last_update` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP;

UPDATE `cities`
SET
  `level` = COALESCE(`level`, 1),
  `durability` = COALESCE(`durability`, 3000),
  `max_durability` = COALESCE(`max_durability`, 3000),
  `is_main_city` = COALESCE(`is_main_city`, 0);

ALTER TABLE `cities`
  MODIFY COLUMN `level` int(11) NOT NULL DEFAULT 1,
  MODIFY COLUMN `durability` int(11) NOT NULL DEFAULT 3000,
  MODIFY COLUMN `max_durability` int(11) NOT NULL DEFAULT 3000,
  MODIFY COLUMN `is_main_city` tinyint(1) NOT NULL DEFAULT 0,
  MODIFY COLUMN `defense_strategy` enum('defense','balanced','production') NOT NULL DEFAULT 'balanced';

-- 旧库若已有一名玩家的多座主城，必须先人工判定正确记录；双插入守卫会以唯一键错误明确中止，绝不猜测删除。 / If a legacy user already has multiple main cities, a human must choose the correct record; the double-insert guard aborts with a unique-key error rather than guessing.
DROP TEMPORARY TABLE IF EXISTS `fireseed_duplicate_main_city_guard`;
CREATE TEMPORARY TABLE `fireseed_duplicate_main_city_guard` (
  `owner_id` int(11) NOT NULL,
  PRIMARY KEY (`owner_id`)
) ENGINE=InnoDB;

INSERT INTO `fireseed_duplicate_main_city_guard` (`owner_id`)
SELECT `owner_id`
FROM `cities`
WHERE `is_main_city` = 1
GROUP BY `owner_id`
HAVING COUNT(*) > 1;

INSERT INTO `fireseed_duplicate_main_city_guard` (`owner_id`)
SELECT `owner_id`
FROM `cities`
WHERE `is_main_city` = 1
GROUP BY `owner_id`
HAVING COUNT(*) > 1;

DROP TEMPORARY TABLE `fireseed_duplicate_main_city_guard`;

-- NULL 可重复而玩家编号不可重复，以生成列兼容 MySQL/MariaDB 的“每人至多一座主城”约束。 / NULL remains repeatable while owner IDs are unique, giving MySQL/MariaDB a portable one-main-city-per-user constraint.
SET @fireseed_main_city_owner_column_exists = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = DATABASE()
    AND table_name = 'cities'
    AND column_name = 'main_city_owner_id'
);
SET @fireseed_main_city_owner_column_sql = IF(
  @fireseed_main_city_owner_column_exists > 0,
  'DO 0',
  'ALTER TABLE `cities` ADD COLUMN `main_city_owner_id` int(11) GENERATED ALWAYS AS (CASE WHEN `is_main_city` = 1 THEN `owner_id` ELSE NULL END) STORED'
);
PREPARE fireseed_stmt FROM @fireseed_main_city_owner_column_sql;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

SET @fireseed_main_city_unique_exists = (
  SELECT COUNT(DISTINCT candidate.`index_name`)
  FROM information_schema.statistics AS candidate
  WHERE candidate.`table_schema` = DATABASE()
    AND candidate.`table_name` = 'cities'
    AND candidate.`column_name` = 'main_city_owner_id'
    AND candidate.`non_unique` = 0
    AND (
      SELECT COUNT(*)
      FROM information_schema.statistics AS member
      WHERE member.`table_schema` = candidate.`table_schema`
        AND member.`table_name` = candidate.`table_name`
        AND member.`index_name` = candidate.`index_name`
    ) = 1
);
SET @fireseed_main_city_unique_sql = IF(
  @fireseed_main_city_unique_exists > 0,
  'DO 0',
  'ALTER TABLE `cities` ADD UNIQUE KEY `uq_cities_one_main_city` (`main_city_owner_id`)'
);
PREPARE fireseed_stmt FROM @fireseed_main_city_unique_sql;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

UPDATE `soldiers`
SET
  `level` = COALESCE(`level`, 1),
  `quantity` = COALESCE(`quantity`, 0),
  `in_training` = COALESCE(`in_training`, 0);

ALTER TABLE `soldiers`
  MODIFY COLUMN `level` int(11) NOT NULL DEFAULT 1,
  MODIFY COLUMN `quantity` int(11) NOT NULL DEFAULT 0,
  MODIFY COLUMN `in_training` int(11) NOT NULL DEFAULT 0;

UPDATE `map_tiles`
SET
  `npc_garrison` = COALESCE(`npc_garrison`, 0),
  `collection_efficiency` = COALESCE(`collection_efficiency`, 100);

ALTER TABLE `map_tiles`
  MODIFY COLUMN `npc_garrison` bigint(20) NOT NULL DEFAULT 0,
  MODIFY COLUMN `collection_efficiency` int(11) NOT NULL DEFAULT 100;

UPDATE `general_skills` SET `slot` = 0 WHERE `slot` IS NULL;
ALTER TABLE `general_skills` MODIFY COLUMN `slot` int(11) NOT NULL DEFAULT 0;

-- 模板武将使用 owner_id=0，因此移除该列上的旧用户外键。 / Template generals use owner_id=0, so remove legacy user foreign keys on that column.
SET @fireseed_general_fk_parts = (
  SELECT GROUP_CONCAT(CONCAT('DROP FOREIGN KEY `', constraint_name, '`') SEPARATOR ', ')
  FROM information_schema.key_column_usage
  WHERE table_schema = DATABASE()
    AND table_name = 'generals'
    AND column_name = 'owner_id'
    AND referenced_table_name IS NOT NULL
);
SET @fireseed_general_fk_sql = IF(
  @fireseed_general_fk_parts IS NULL,
  'DO 0',
  CONCAT('ALTER TABLE `generals` ', @fireseed_general_fk_parts)
);
PREPARE fireseed_stmt FROM @fireseed_general_fk_sql;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

UPDATE `generals` SET `owner_id` = 0 WHERE `owner_id` IS NULL;
ALTER TABLE `generals` MODIFY COLUMN `owner_id` int(11) NOT NULL DEFAULT 0;

-- 扩展战果枚举，同时保留所有旧值。 / Expand battle outcomes while preserving every legacy value.
ALTER TABLE `battles`
  MODIFY COLUMN `result`
    enum('pending','attacker_win_big','attacker_win','defender_win_big','defender_win','draw')
    NOT NULL DEFAULT 'pending';

-- 保存出发时攻击快照，并让解散军队不再级联删除永久战报。 / Preserve departure snapshots and keep permanent reports after an army is disbanded.
SET @fireseed_battle_columns = CONCAT_WS(', ',
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'battles' AND column_name = 'attacker_power_snapshot'), NULL, 'ADD COLUMN `attacker_power_snapshot` bigint(20) NOT NULL DEFAULT 0 AFTER `attacker_army_id`'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'battles' AND column_name = 'attacker_damage_reduction_snapshot'), NULL, 'ADD COLUMN `attacker_damage_reduction_snapshot` decimal(8,3) NOT NULL DEFAULT 0.000 AFTER `attacker_power_snapshot`'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'battles' AND column_name = 'attacker_composition_snapshot'), NULL, 'ADD COLUMN `attacker_composition_snapshot` text DEFAULT NULL AFTER `attacker_damage_reduction_snapshot`'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'battles' AND column_name = 'attacker_name_snapshot'), NULL, 'ADD COLUMN `attacker_name_snapshot` varchar(50) DEFAULT NULL AFTER `attacker_composition_snapshot`')
);
SET @fireseed_battle_ddl = IF(
  @fireseed_battle_columns = '',
  'DO 0',
  CONCAT('ALTER TABLE `battles` ', @fireseed_battle_columns)
);
PREPARE fireseed_stmt FROM @fireseed_battle_ddl;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

UPDATE `battles` b
INNER JOIN `armies` a ON a.army_id = b.attacker_army_id
SET b.attacker_name_snapshot = COALESCE(b.attacker_name_snapshot, a.name);

SET @fireseed_battle_attacker_fk_parts = (
  SELECT GROUP_CONCAT(
    CONCAT('DROP FOREIGN KEY `', constraint_name, '`')
    SEPARATOR ', '
  )
  FROM information_schema.key_column_usage
  WHERE table_schema = DATABASE()
    AND table_name = 'battles'
    AND column_name = 'attacker_army_id'
    AND referenced_table_name IS NOT NULL
);
SET @fireseed_battle_attacker_fk_sql = IF(
  @fireseed_battle_attacker_fk_parts IS NULL,
  'DO 0',
  CONCAT('ALTER TABLE `battles` ', @fireseed_battle_attacker_fk_parts)
);
PREPARE fireseed_stmt FROM @fireseed_battle_attacker_fk_sql;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

ALTER TABLE `battles`
  MODIFY COLUMN `attacker_army_id` int(11) DEFAULT NULL;
ALTER TABLE `battles`
  ADD CONSTRAINT `battles_ibfk_1`
    FOREIGN KEY (`attacker_army_id`) REFERENCES `armies` (`army_id`)
    ON DELETE SET NULL;

-- 旧库若意外存在重复资源行，保留每种余额的最大值并删除其余行；选择最大值可避免把重复快照相加铸币。 / If a legacy database has duplicate resource rows, keep each balance's maximum and remove the extras; maxima avoid minting currency by summing duplicate snapshots.
DROP TEMPORARY TABLE IF EXISTS `fireseed_resource_merge`;
CREATE TEMPORARY TABLE `fireseed_resource_merge` AS
SELECT `user_id`,
       MIN(`resource_id`) AS `keep_resource_id`,
       MAX(`bright_crystal`) AS `bright_crystal`,
       MAX(`warm_crystal`) AS `warm_crystal`,
       MAX(`cold_crystal`) AS `cold_crystal`,
       MAX(`green_crystal`) AS `green_crystal`,
       MAX(`day_crystal`) AS `day_crystal`,
       MAX(`night_crystal`) AS `night_crystal`,
       MAX(`last_update`) AS `last_update`
FROM `resources`
GROUP BY `user_id`;

UPDATE `resources` r
INNER JOIN `fireseed_resource_merge` merged
  ON merged.`keep_resource_id` = r.`resource_id`
SET r.`bright_crystal` = merged.`bright_crystal`,
    r.`warm_crystal` = merged.`warm_crystal`,
    r.`cold_crystal` = merged.`cold_crystal`,
    r.`green_crystal` = merged.`green_crystal`,
    r.`day_crystal` = merged.`day_crystal`,
    r.`night_crystal` = merged.`night_crystal`,
    r.`last_update` = merged.`last_update`;

DELETE duplicate_resource
FROM `resources` duplicate_resource
INNER JOIN `fireseed_resource_merge` merged
  ON merged.`user_id` = duplicate_resource.`user_id`
WHERE duplicate_resource.`resource_id` <> merged.`keep_resource_id`;

DROP TEMPORARY TABLE `fireseed_resource_merge`;

SET @fireseed_resource_duplicate_count = (
  SELECT COUNT(*)
  FROM (
    SELECT `user_id`
    FROM `resources`
    GROUP BY `user_id`
    HAVING COUNT(*) > 1
  ) AS `fireseed_remaining_resource_duplicates`
);

-- 确定每名玩家只有一行后，将旧普通索引补成唯一索引。 / Promote the legacy ordinary index after every user has exactly one row.
SET @fireseed_resource_unique_exists = (
  SELECT COUNT(DISTINCT candidate.`index_name`)
  FROM information_schema.statistics AS candidate
  WHERE candidate.`table_schema` = DATABASE()
    AND candidate.`table_name` = 'resources'
    AND candidate.`column_name` = 'user_id'
    AND candidate.`non_unique` = 0
    AND (
      SELECT COUNT(*)
      FROM information_schema.statistics AS member
      WHERE member.`table_schema` = candidate.`table_schema`
        AND member.`table_name` = candidate.`table_name`
        AND member.`index_name` = candidate.`index_name`
    ) = 1
);
SET @fireseed_resource_named_index_exists = (
  SELECT COUNT(*)
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'resources'
    AND index_name = 'uq_resources_user_id'
);
SET @fireseed_resource_unique_sql = IF(
  @fireseed_resource_unique_exists > 0,
  'DO 0',
  IF(
    @fireseed_resource_named_index_exists > 0,
    'ALTER TABLE `resources` DROP INDEX `uq_resources_user_id`, ADD UNIQUE KEY `uq_resources_user_id` (`user_id`)',
    'ALTER TABLE `resources` ADD UNIQUE KEY `uq_resources_user_id` (`user_id`)'
  )
);
PREPARE fireseed_stmt FROM @fireseed_resource_unique_sql;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

-- 以下 DDL 与 gameplay_expansion.sql 自包含同步，避免 SOURCE 在 mysqli 或迁移器中失效。 / The following DDL is self-contained with gameplay_expansion.sql because SOURCE is unavailable to mysqli and many migration runners.
CREATE TABLE IF NOT EXISTS `gameplay_wallets` (
  `user_id` int(11) NOT NULL,
  `skill_points` int(11) NOT NULL DEFAULT 0,
  `merit_points` int(11) NOT NULL DEFAULT 0,
  `arena_tokens` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `gameplay_wallets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `skill_card_catalog` (
  `card_id` int(11) NOT NULL AUTO_INCREMENT,
  `card_code` varchar(64) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `rarity` enum('B','A','S','SS','P') NOT NULL DEFAULT 'B',
  `element` enum('亮晶晶','暖洋洋','冷冰冰','郁萌萌','昼闪闪','夜静静') NOT NULL,
  `activation_type` enum('active','passive') NOT NULL DEFAULT 'passive',
  `category` enum('internal','march','attack','defense','support','special') NOT NULL,
  `effect_json` text NOT NULL,
  `base_cooldown` int(11) NOT NULL DEFAULT 0,
  `max_level` int(11) NOT NULL DEFAULT 5,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`card_id`),
  UNIQUE KEY `card_code` (`card_code`),
  KEY `rarity_active` (`rarity`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_skill_cards` (
  `user_id` int(11) NOT NULL,
  `card_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`card_id`),
  CONSTRAINT `user_skill_cards_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `user_skill_cards_ibfk_2` FOREIGN KEY (`card_id`) REFERENCES `skill_card_catalog` (`card_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `equipped_skill_cards` (
  `skill_id` int(11) NOT NULL,
  `card_id` int(11) NOT NULL,
  `equipped_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`skill_id`),
  KEY `card_id` (`card_id`),
  CONSTRAINT `equipped_skill_cards_ibfk_1` FOREIGN KEY (`skill_id`) REFERENCES `general_skills` (`skill_id`) ON DELETE CASCADE,
  CONSTRAINT `equipped_skill_cards_ibfk_2` FOREIGN KEY (`card_id`) REFERENCES `skill_card_catalog` (`card_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `skill_cooldowns` (
  `skill_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `ready_at` datetime NOT NULL,
  PRIMARY KEY (`skill_id`,`user_id`),
  CONSTRAINT `skill_cooldowns_ibfk_1` FOREIGN KEY (`skill_id`) REFERENCES `general_skills` (`skill_id`) ON DELETE CASCADE,
  CONSTRAINT `skill_cooldowns_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `active_skill_effects` (
  `skill_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `general_id` int(11) NOT NULL,
  `effect_json` text NOT NULL,
  `activated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`skill_id`),
  KEY `expires_at` (`expires_at`),
  KEY `user_id` (`user_id`),
  KEY `general_id` (`general_id`),
  CONSTRAINT `active_skill_effects_ibfk_1` FOREIGN KEY (`skill_id`) REFERENCES `general_skills` (`skill_id`) ON DELETE CASCADE,
  CONSTRAINT `active_skill_effects_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `active_skill_effects_ibfk_3` FOREIGN KEY (`general_id`) REFERENCES `generals` (`general_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `skill_draw_history` (
  `draw_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `card_id` int(11) NOT NULL,
  `rarity` enum('B','A','S','SS','P') NOT NULL,
  `cost_night` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`draw_id`),
  KEY `user_created` (`user_id`,`created_at`),
  CONSTRAINT `skill_draw_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `skill_draw_history_ibfk_2` FOREIGN KEY (`card_id`) REFERENCES `skill_card_catalog` (`card_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `recruitment_history` (
  `recruitment_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `template_general_id` int(11) NOT NULL,
  `general_id` int(11) NOT NULL,
  `recruit_type` enum('starter','normal','advanced','resonance','quest','event') NOT NULL,
  `rarity` enum('B','A','S','SS','P') NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`recruitment_id`),
  KEY `user_created` (`user_id`,`created_at`),
  CONSTRAINT `recruitment_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `recruitment_history_ibfk_2` FOREIGN KEY (`template_general_id`) REFERENCES `generals` (`general_id`) ON DELETE RESTRICT,
  CONSTRAINT `recruitment_history_ibfk_3` FOREIGN KEY (`general_id`) REFERENCES `generals` (`general_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `general_template_catalog` (
  `template_code` varchar(16) NOT NULL,
  `general_id` int(11) NOT NULL,
  PRIMARY KEY (`template_code`),
  UNIQUE KEY `general_id` (`general_id`),
  CONSTRAINT `general_template_catalog_ibfk_1` FOREIGN KEY (`general_id`) REFERENCES `generals` (`general_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `general_progression` (
  `general_id` int(11) NOT NULL,
  `break_level` int(11) NOT NULL DEFAULT 0,
  `experience` int(11) NOT NULL DEFAULT 0,
  `skill_points_spent` int(11) NOT NULL DEFAULT 0,
  `last_hp_recovery` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`general_id`),
  CONSTRAINT `general_progression_ibfk_1` FOREIGN KEY (`general_id`) REFERENCES `generals` (`general_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_items` (
  `user_id` int(11) NOT NULL,
  `item_code` varchar(64) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`item_code`),
  CONSTRAINT `user_items_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `shop_catalog` (
  `shop_item_id` int(11) NOT NULL AUTO_INCREMENT,
  `item_code` varchar(64) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `cost_json` text NOT NULL,
  `grant_json` text NOT NULL,
  `daily_limit` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`shop_item_id`),
  UNIQUE KEY `item_code` (`item_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `shop_purchases` (
  `purchase_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `shop_item_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `cost_json` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`purchase_id`),
  KEY `user_item_created` (`user_id`,`shop_item_id`,`created_at`),
  CONSTRAINT `shop_purchases_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `shop_purchases_ibfk_2` FOREIGN KEY (`shop_item_id`) REFERENCES `shop_catalog` (`shop_item_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `alliances` (
  `alliance_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `tag` varchar(12) NOT NULL,
  `description` text DEFAULT NULL,
  `leader_id` int(11) DEFAULT NULL,
  `level` int(11) NOT NULL DEFAULT 1,
  `experience` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`alliance_id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `tag` (`tag`),
  KEY `leader_id` (`leader_id`),
  CONSTRAINT `alliances_ibfk_1` FOREIGN KEY (`leader_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `alliance_members` (
  `member_id` int(11) NOT NULL AUTO_INCREMENT,
  `alliance_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` enum('leader','officer','member') NOT NULL DEFAULT 'member',
  `contribution` int(11) NOT NULL DEFAULT 0,
  `joined_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`member_id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `alliance_role` (`alliance_id`,`role`),
  CONSTRAINT `alliance_members_ibfk_1` FOREIGN KEY (`alliance_id`) REFERENCES `alliances` (`alliance_id`) ON DELETE CASCADE,
  CONSTRAINT `alliance_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `alliance_applications` (
  `application_id` int(11) NOT NULL AUTO_INCREMENT,
  `alliance_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` varchar(255) DEFAULT NULL,
  `status` enum('pending','accepted','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`application_id`),
  UNIQUE KEY `alliance_user` (`alliance_id`,`user_id`),
  KEY `alliance_status` (`alliance_id`,`status`),
  CONSTRAINT `alliance_applications_ibfk_1` FOREIGN KEY (`alliance_id`) REFERENCES `alliances` (`alliance_id`) ON DELETE CASCADE,
  CONSTRAINT `alliance_applications_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `alliance_aid_log` (
  `aid_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `alliance_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `resource_type` enum('bright','warm','cold','green','day','night') NOT NULL,
  `amount` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`aid_id`),
  KEY `sender_created` (`sender_id`,`created_at`),
  KEY `receiver_created` (`receiver_id`,`created_at`),
  CONSTRAINT `alliance_aid_log_ibfk_1` FOREIGN KEY (`alliance_id`) REFERENCES `alliances` (`alliance_id`) ON DELETE CASCADE,
  CONSTRAINT `alliance_aid_log_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `alliance_aid_log_ibfk_3` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `alliance_operations` (
  `operation_id` int(11) NOT NULL AUTO_INCREMENT,
  `alliance_id` int(11) NOT NULL,
  `creator_id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `target_type` enum('tile','city','army') NOT NULL,
  `target_id` int(11) NOT NULL,
  `target_x` int(11) NOT NULL,
  `target_y` int(11) NOT NULL,
  `launch_at` datetime NOT NULL,
  `status` enum('open','launched','completed','cancelled') NOT NULL DEFAULT 'open',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`operation_id`),
  KEY `alliance_status_launch` (`alliance_id`,`status`,`launch_at`),
  CONSTRAINT `alliance_operations_ibfk_1` FOREIGN KEY (`alliance_id`) REFERENCES `alliances` (`alliance_id`) ON DELETE CASCADE,
  CONSTRAINT `alliance_operations_ibfk_2` FOREIGN KEY (`creator_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `alliance_operation_armies` (
  `operation_id` int(11) NOT NULL,
  `army_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `joined_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`operation_id`,`army_id`),
  UNIQUE KEY `army_id` (`army_id`),
  CONSTRAINT `alliance_operation_armies_ibfk_1` FOREIGN KEY (`operation_id`) REFERENCES `alliance_operations` (`operation_id`) ON DELETE CASCADE,
  CONSTRAINT `alliance_operation_armies_ibfk_2` FOREIGN KEY (`army_id`) REFERENCES `armies` (`army_id`) ON DELETE CASCADE,
  CONSTRAINT `alliance_operation_armies_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `world_sites` (
  `site_id` int(11) NOT NULL AUTO_INCREMENT,
  `tile_id` int(11) NOT NULL,
  `site_code` varchar(64) NOT NULL,
  `site_type` enum('gateway','silver_hole') NOT NULL,
  `display_name` varchar(100) NOT NULL,
  `max_durability` bigint(20) NOT NULL,
  `durability` bigint(20) NOT NULL,
  `owner_id` int(11) DEFAULT NULL,
  `captured_at` datetime DEFAULT NULL,
  `occupation_started_at` datetime DEFAULT NULL,
  PRIMARY KEY (`site_id`),
  UNIQUE KEY `tile_id` (`tile_id`),
  UNIQUE KEY `site_code` (`site_code`),
  KEY `site_type_owner` (`site_type`,`owner_id`),
  CONSTRAINT `world_sites_ibfk_1` FOREIGN KEY (`tile_id`) REFERENCES `map_tiles` (`tile_id`) ON DELETE CASCADE,
  CONSTRAINT `world_sites_ibfk_2` FOREIGN KEY (`owner_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 在不重建地图、不覆盖玩家城市或领地的情况下迁移银白之孔与十二门。 / Migrate the Silver Hole and Twelve Gateways without rebuilding the map or overwriting player cities or territories.
DROP TEMPORARY TABLE IF EXISTS `fireseed_world_site_seed`;
CREATE TEMPORARY TABLE `fireseed_world_site_seed` (
  `site_code` varchar(64) NOT NULL,
  `site_type` enum('gateway','silver_hole') NOT NULL,
  `display_name` varchar(100) NOT NULL,
  `x` int(11) NOT NULL,
  `y` int(11) NOT NULL,
  `max_durability` bigint(20) NOT NULL,
  `npc_garrison` bigint(20) NOT NULL,
  PRIMARY KEY (`site_code`)
) ENGINE=InnoDB;

INSERT INTO `fireseed_world_site_seed`
(`site_code`,`site_type`,`display_name`,`x`,`y`,`max_durability`,`npc_garrison`) VALUES
('silver_hole','silver_hole','银白之孔',256,256,1000000000,1000000000),
('gateway_minjing','gateway','明京 Minjing',256,77,115330,3814697),
('gateway_ninghai','gateway','宁海 Ninghai',346,101,115330,3814697),
('gateway_wuyue','gateway','五岳 Wuyue',411,167,115330,3814697),
('gateway_luhai','gateway','陆合 Luhai',435,256,115330,3814697),
('gateway_misawa','gateway','米萨瓦 Misawa',411,346,115330,3814697),
('gateway_kanata','gateway','卡拉塔 Kanata',346,411,115330,3814697),
('gateway_yozora','gateway','约左拉 Yozora',256,435,115330,3814697),
('gateway_naomi','gateway','娜奥美 Naomi',167,411,115330,3814697),
('gateway_minster','gateway','明斯特尔 Minster',101,346,115330,3814697),
('gateway_elise','gateway','艾尔利斯 Elise',77,256,115330,3814697),
('gateway_redknife','gateway','雷德奈芙 Redknife',101,167,115330,3814697),
('gateway_caeperra','gateway','开里培拉 Caeperra',167,101,115330,3814697);

-- 已受管理的世界地点若与城市重叠，迁移必须明确失败，不能悄悄搬动或破坏城市。 / An already managed world site overlapping a city aborts explicitly; migration must neither move it silently nor damage the city.
DROP TEMPORARY TABLE IF EXISTS `fireseed_world_site_city_guard`;
CREATE TEMPORARY TABLE `fireseed_world_site_city_guard` (
  `site_id` int(11) NOT NULL,
  PRIMARY KEY (`site_id`)
) ENGINE=InnoDB;

INSERT INTO `fireseed_world_site_city_guard` (`site_id`)
SELECT site.`site_id`
FROM `world_sites` AS site
JOIN `map_tiles` AS tile ON tile.`tile_id` = site.`tile_id`
JOIN `cities` AS city ON city.`x` = tile.`x` AND city.`y` = tile.`y`;

INSERT INTO `fireseed_world_site_city_guard` (`site_id`)
SELECT site.`site_id`
FROM `world_sites` AS site
JOIN `map_tiles` AS tile ON tile.`tile_id` = site.`tile_id`
JOIN `cities` AS city ON city.`x` = tile.`x` AND city.`y` = tile.`y`;

DROP TEMPORARY TABLE `fireseed_world_site_city_guard`;

DROP TEMPORARY TABLE IF EXISTS `fireseed_world_site_assignment`;
CREATE TEMPORARY TABLE `fireseed_world_site_assignment` (
  `site_code` varchar(64) NOT NULL,
  `tile_id` int(11) NOT NULL,
  PRIMARY KEY (`site_code`),
  UNIQUE KEY `tile_id` (`tile_id`)
) ENGINE=InnoDB;

-- 可重复执行时优先保留已按规范登记的地点。 / Prefer already canonical site registrations on reruns.
INSERT INTO `fireseed_world_site_assignment` (`site_code`,`tile_id`)
SELECT seed.`site_code`, site.`tile_id`
FROM `fireseed_world_site_seed` AS seed
JOIN `world_sites` AS site ON site.`site_code` = seed.`site_code`;

-- 旧版可能已经登记或随机生成银白之孔；按中心距离、坐标和编号确定性复用。 / Legacy versions may have registered or randomly generated a Silver Hole; reuse one deterministically by center distance, coordinates, and ID.
INSERT IGNORE INTO `fireseed_world_site_assignment` (`site_code`,`tile_id`)
SELECT 'silver_hole', site.`tile_id`
FROM `world_sites` AS site
JOIN `map_tiles` AS tile ON tile.`tile_id` = site.`tile_id`
LEFT JOIN `cities` AS city ON city.`x` = tile.`x` AND city.`y` = tile.`y`
WHERE site.`site_type` = 'silver_hole'
  AND city.`city_id` IS NULL
ORDER BY
  ABS(tile.`x` - 256) + ABS(tile.`y` - 256),
  tile.`x`,
  tile.`y`,
  tile.`tile_id`
LIMIT 1;

INSERT IGNORE INTO `fireseed_world_site_assignment` (`site_code`,`tile_id`)
SELECT 'silver_hole', tile.`tile_id`
FROM `map_tiles` AS tile
LEFT JOIN `cities` AS city ON city.`x` = tile.`x` AND city.`y` = tile.`y`
LEFT JOIN `world_sites` AS site ON site.`tile_id` = tile.`tile_id`
LEFT JOIN `fireseed_world_site_assignment` AS assigned
  ON assigned.`tile_id` = tile.`tile_id`
WHERE tile.`subtype` = 'silver_hole'
  AND city.`city_id` IS NULL
  AND site.`site_id` IS NULL
  AND assigned.`site_code` IS NULL
ORDER BY
  IF(tile.`owner_id` IS NULL, 0, 1),
  ABS(tile.`x` - 256) + ABS(tile.`y` - 256),
  tile.`x`,
  tile.`y`,
  tile.`tile_id`
LIMIT 1;

-- 没有旧银白之孔时，选择离中心最近的无主、无城、未受管理格。 / If no legacy Silver Hole exists, choose the nearest unowned, city-free, unmanaged tile.
INSERT IGNORE INTO `fireseed_world_site_assignment` (`site_code`,`tile_id`)
SELECT 'silver_hole', tile.`tile_id`
FROM `map_tiles` AS tile
LEFT JOIN `cities` AS city ON city.`x` = tile.`x` AND city.`y` = tile.`y`
LEFT JOIN `world_sites` AS site ON site.`tile_id` = tile.`tile_id`
LEFT JOIN `fireseed_world_site_assignment` AS assigned
  ON assigned.`tile_id` = tile.`tile_id`
WHERE tile.`owner_id` IS NULL
  AND city.`city_id` IS NULL
  AND site.`site_id` IS NULL
  AND assigned.`site_code` IS NULL
ORDER BY
  ABS(tile.`x` - 256) + ABS(tile.`y` - 256),
  IF(tile.`type` = 'empty', 0, 1),
  tile.`x`,
  tile.`y`,
  tile.`tile_id`
LIMIT 1;

-- 十二门按固定顺序贪心选取目标坐标最近的安全非玩家格，避免多个门竞争同一格。 / In fixed order, greedily place each Gateway on the nearest safe non-player tile so no two sites compete for one tile.
INSERT IGNORE INTO `fireseed_world_site_assignment` (`site_code`,`tile_id`)
SELECT 'gateway_minjing', tile.`tile_id`
FROM `map_tiles` AS tile
LEFT JOIN `cities` AS city ON city.`x` = tile.`x` AND city.`y` = tile.`y`
LEFT JOIN `world_sites` AS site ON site.`tile_id` = tile.`tile_id`
LEFT JOIN `fireseed_world_site_assignment` AS assigned ON assigned.`tile_id` = tile.`tile_id`
WHERE tile.`owner_id` IS NULL AND city.`city_id` IS NULL
  AND site.`site_id` IS NULL AND assigned.`site_code` IS NULL
  AND COALESCE(tile.`subtype`, '') <> 'silver_hole'
ORDER BY ABS(tile.`x` - 256) + ABS(tile.`y` - 77), IF(tile.`type` = 'empty', 0, 1), tile.`x`, tile.`y`, tile.`tile_id`
LIMIT 1;

INSERT IGNORE INTO `fireseed_world_site_assignment` (`site_code`,`tile_id`)
SELECT 'gateway_ninghai', tile.`tile_id`
FROM `map_tiles` AS tile
LEFT JOIN `cities` AS city ON city.`x` = tile.`x` AND city.`y` = tile.`y`
LEFT JOIN `world_sites` AS site ON site.`tile_id` = tile.`tile_id`
LEFT JOIN `fireseed_world_site_assignment` AS assigned ON assigned.`tile_id` = tile.`tile_id`
WHERE tile.`owner_id` IS NULL AND city.`city_id` IS NULL
  AND site.`site_id` IS NULL AND assigned.`site_code` IS NULL
  AND COALESCE(tile.`subtype`, '') <> 'silver_hole'
ORDER BY ABS(tile.`x` - 346) + ABS(tile.`y` - 101), IF(tile.`type` = 'empty', 0, 1), tile.`x`, tile.`y`, tile.`tile_id`
LIMIT 1;

INSERT IGNORE INTO `fireseed_world_site_assignment` (`site_code`,`tile_id`)
SELECT 'gateway_wuyue', tile.`tile_id`
FROM `map_tiles` AS tile
LEFT JOIN `cities` AS city ON city.`x` = tile.`x` AND city.`y` = tile.`y`
LEFT JOIN `world_sites` AS site ON site.`tile_id` = tile.`tile_id`
LEFT JOIN `fireseed_world_site_assignment` AS assigned ON assigned.`tile_id` = tile.`tile_id`
WHERE tile.`owner_id` IS NULL AND city.`city_id` IS NULL
  AND site.`site_id` IS NULL AND assigned.`site_code` IS NULL
  AND COALESCE(tile.`subtype`, '') <> 'silver_hole'
ORDER BY ABS(tile.`x` - 411) + ABS(tile.`y` - 167), IF(tile.`type` = 'empty', 0, 1), tile.`x`, tile.`y`, tile.`tile_id`
LIMIT 1;

INSERT IGNORE INTO `fireseed_world_site_assignment` (`site_code`,`tile_id`)
SELECT 'gateway_luhai', tile.`tile_id`
FROM `map_tiles` AS tile
LEFT JOIN `cities` AS city ON city.`x` = tile.`x` AND city.`y` = tile.`y`
LEFT JOIN `world_sites` AS site ON site.`tile_id` = tile.`tile_id`
LEFT JOIN `fireseed_world_site_assignment` AS assigned ON assigned.`tile_id` = tile.`tile_id`
WHERE tile.`owner_id` IS NULL AND city.`city_id` IS NULL
  AND site.`site_id` IS NULL AND assigned.`site_code` IS NULL
  AND COALESCE(tile.`subtype`, '') <> 'silver_hole'
ORDER BY ABS(tile.`x` - 435) + ABS(tile.`y` - 256), IF(tile.`type` = 'empty', 0, 1), tile.`x`, tile.`y`, tile.`tile_id`
LIMIT 1;

INSERT IGNORE INTO `fireseed_world_site_assignment` (`site_code`,`tile_id`)
SELECT 'gateway_misawa', tile.`tile_id`
FROM `map_tiles` AS tile
LEFT JOIN `cities` AS city ON city.`x` = tile.`x` AND city.`y` = tile.`y`
LEFT JOIN `world_sites` AS site ON site.`tile_id` = tile.`tile_id`
LEFT JOIN `fireseed_world_site_assignment` AS assigned ON assigned.`tile_id` = tile.`tile_id`
WHERE tile.`owner_id` IS NULL AND city.`city_id` IS NULL
  AND site.`site_id` IS NULL AND assigned.`site_code` IS NULL
  AND COALESCE(tile.`subtype`, '') <> 'silver_hole'
ORDER BY ABS(tile.`x` - 411) + ABS(tile.`y` - 346), IF(tile.`type` = 'empty', 0, 1), tile.`x`, tile.`y`, tile.`tile_id`
LIMIT 1;

INSERT IGNORE INTO `fireseed_world_site_assignment` (`site_code`,`tile_id`)
SELECT 'gateway_kanata', tile.`tile_id`
FROM `map_tiles` AS tile
LEFT JOIN `cities` AS city ON city.`x` = tile.`x` AND city.`y` = tile.`y`
LEFT JOIN `world_sites` AS site ON site.`tile_id` = tile.`tile_id`
LEFT JOIN `fireseed_world_site_assignment` AS assigned ON assigned.`tile_id` = tile.`tile_id`
WHERE tile.`owner_id` IS NULL AND city.`city_id` IS NULL
  AND site.`site_id` IS NULL AND assigned.`site_code` IS NULL
  AND COALESCE(tile.`subtype`, '') <> 'silver_hole'
ORDER BY ABS(tile.`x` - 346) + ABS(tile.`y` - 411), IF(tile.`type` = 'empty', 0, 1), tile.`x`, tile.`y`, tile.`tile_id`
LIMIT 1;

INSERT IGNORE INTO `fireseed_world_site_assignment` (`site_code`,`tile_id`)
SELECT 'gateway_yozora', tile.`tile_id`
FROM `map_tiles` AS tile
LEFT JOIN `cities` AS city ON city.`x` = tile.`x` AND city.`y` = tile.`y`
LEFT JOIN `world_sites` AS site ON site.`tile_id` = tile.`tile_id`
LEFT JOIN `fireseed_world_site_assignment` AS assigned ON assigned.`tile_id` = tile.`tile_id`
WHERE tile.`owner_id` IS NULL AND city.`city_id` IS NULL
  AND site.`site_id` IS NULL AND assigned.`site_code` IS NULL
  AND COALESCE(tile.`subtype`, '') <> 'silver_hole'
ORDER BY ABS(tile.`x` - 256) + ABS(tile.`y` - 435), IF(tile.`type` = 'empty', 0, 1), tile.`x`, tile.`y`, tile.`tile_id`
LIMIT 1;

INSERT IGNORE INTO `fireseed_world_site_assignment` (`site_code`,`tile_id`)
SELECT 'gateway_naomi', tile.`tile_id`
FROM `map_tiles` AS tile
LEFT JOIN `cities` AS city ON city.`x` = tile.`x` AND city.`y` = tile.`y`
LEFT JOIN `world_sites` AS site ON site.`tile_id` = tile.`tile_id`
LEFT JOIN `fireseed_world_site_assignment` AS assigned ON assigned.`tile_id` = tile.`tile_id`
WHERE tile.`owner_id` IS NULL AND city.`city_id` IS NULL
  AND site.`site_id` IS NULL AND assigned.`site_code` IS NULL
  AND COALESCE(tile.`subtype`, '') <> 'silver_hole'
ORDER BY ABS(tile.`x` - 167) + ABS(tile.`y` - 411), IF(tile.`type` = 'empty', 0, 1), tile.`x`, tile.`y`, tile.`tile_id`
LIMIT 1;

INSERT IGNORE INTO `fireseed_world_site_assignment` (`site_code`,`tile_id`)
SELECT 'gateway_minster', tile.`tile_id`
FROM `map_tiles` AS tile
LEFT JOIN `cities` AS city ON city.`x` = tile.`x` AND city.`y` = tile.`y`
LEFT JOIN `world_sites` AS site ON site.`tile_id` = tile.`tile_id`
LEFT JOIN `fireseed_world_site_assignment` AS assigned ON assigned.`tile_id` = tile.`tile_id`
WHERE tile.`owner_id` IS NULL AND city.`city_id` IS NULL
  AND site.`site_id` IS NULL AND assigned.`site_code` IS NULL
  AND COALESCE(tile.`subtype`, '') <> 'silver_hole'
ORDER BY ABS(tile.`x` - 101) + ABS(tile.`y` - 346), IF(tile.`type` = 'empty', 0, 1), tile.`x`, tile.`y`, tile.`tile_id`
LIMIT 1;

INSERT IGNORE INTO `fireseed_world_site_assignment` (`site_code`,`tile_id`)
SELECT 'gateway_elise', tile.`tile_id`
FROM `map_tiles` AS tile
LEFT JOIN `cities` AS city ON city.`x` = tile.`x` AND city.`y` = tile.`y`
LEFT JOIN `world_sites` AS site ON site.`tile_id` = tile.`tile_id`
LEFT JOIN `fireseed_world_site_assignment` AS assigned ON assigned.`tile_id` = tile.`tile_id`
WHERE tile.`owner_id` IS NULL AND city.`city_id` IS NULL
  AND site.`site_id` IS NULL AND assigned.`site_code` IS NULL
  AND COALESCE(tile.`subtype`, '') <> 'silver_hole'
ORDER BY ABS(tile.`x` - 77) + ABS(tile.`y` - 256), IF(tile.`type` = 'empty', 0, 1), tile.`x`, tile.`y`, tile.`tile_id`
LIMIT 1;

INSERT IGNORE INTO `fireseed_world_site_assignment` (`site_code`,`tile_id`)
SELECT 'gateway_redknife', tile.`tile_id`
FROM `map_tiles` AS tile
LEFT JOIN `cities` AS city ON city.`x` = tile.`x` AND city.`y` = tile.`y`
LEFT JOIN `world_sites` AS site ON site.`tile_id` = tile.`tile_id`
LEFT JOIN `fireseed_world_site_assignment` AS assigned ON assigned.`tile_id` = tile.`tile_id`
WHERE tile.`owner_id` IS NULL AND city.`city_id` IS NULL
  AND site.`site_id` IS NULL AND assigned.`site_code` IS NULL
  AND COALESCE(tile.`subtype`, '') <> 'silver_hole'
ORDER BY ABS(tile.`x` - 101) + ABS(tile.`y` - 167), IF(tile.`type` = 'empty', 0, 1), tile.`x`, tile.`y`, tile.`tile_id`
LIMIT 1;

INSERT IGNORE INTO `fireseed_world_site_assignment` (`site_code`,`tile_id`)
SELECT 'gateway_caeperra', tile.`tile_id`
FROM `map_tiles` AS tile
LEFT JOIN `cities` AS city ON city.`x` = tile.`x` AND city.`y` = tile.`y`
LEFT JOIN `world_sites` AS site ON site.`tile_id` = tile.`tile_id`
LEFT JOIN `fireseed_world_site_assignment` AS assigned ON assigned.`tile_id` = tile.`tile_id`
WHERE tile.`owner_id` IS NULL AND city.`city_id` IS NULL
  AND site.`site_id` IS NULL AND assigned.`site_code` IS NULL
  AND COALESCE(tile.`subtype`, '') <> 'silver_hole'
ORDER BY ABS(tile.`x` - 167) + ABS(tile.`y` - 101), IF(tile.`type` = 'empty', 0, 1), tile.`x`, tile.`y`, tile.`tile_id`
LIMIT 1;

-- 任一地点找不到安全格时，以缺失代码的唯一键冲突明确中止，绝不留下半套地点。 / If any site lacks a safe tile, a unique-key conflict naming the missing code aborts explicitly instead of leaving a partial site set.
DROP TEMPORARY TABLE IF EXISTS `fireseed_missing_world_site_guard`;
CREATE TEMPORARY TABLE `fireseed_missing_world_site_guard` (
  `site_code` varchar(64) NOT NULL,
  PRIMARY KEY (`site_code`)
) ENGINE=InnoDB;

INSERT INTO `fireseed_missing_world_site_guard` (`site_code`)
SELECT seed.`site_code`
FROM `fireseed_world_site_seed` AS seed
LEFT JOIN `fireseed_world_site_assignment` AS assigned
  ON assigned.`site_code` = seed.`site_code`
WHERE assigned.`site_code` IS NULL;

INSERT INTO `fireseed_missing_world_site_guard` (`site_code`)
SELECT seed.`site_code`
FROM `fireseed_world_site_seed` AS seed
LEFT JOIN `fireseed_world_site_assignment` AS assigned
  ON assigned.`site_code` = seed.`site_code`
WHERE assigned.`site_code` IS NULL;

DROP TEMPORARY TABLE `fireseed_missing_world_site_guard`;

START TRANSACTION;

-- 将被选中的旧登记原位归一化为唯一规范代码，保留其耐久与占领历史。 / Normalize the chosen legacy registration in place, preserving durability and capture history.
UPDATE `world_sites` AS site
JOIN `fireseed_world_site_assignment` AS assigned
  ON assigned.`tile_id` = site.`tile_id`
SET
  site.`site_code` = 'silver_hole',
  site.`site_type` = 'silver_hole',
  site.`display_name` = '银白之孔',
  site.`max_durability` = 1000000000,
  site.`durability` = LEAST(site.`durability`, 1000000000)
WHERE assigned.`site_code` = 'silver_hole'
  AND site.`site_type` = 'silver_hole';

DELETE FROM `world_sites`
WHERE `site_type` = 'silver_hole'
  AND `site_code` <> 'silver_hole';

UPDATE `map_tiles` AS tile
JOIN `fireseed_world_site_assignment` AS assigned
  ON assigned.`tile_id` = tile.`tile_id`
JOIN `fireseed_world_site_seed` AS seed
  ON seed.`site_code` = assigned.`site_code`
LEFT JOIN `world_sites` AS existing_site
  ON existing_site.`tile_id` = tile.`tile_id`
SET
  tile.`type` = IF(seed.`site_type` = 'silver_hole', 'special', 'npc_fort'),
  tile.`subtype` = seed.`site_type`,
  tile.`owner_id` = COALESCE(tile.`owner_id`, existing_site.`owner_id`),
  tile.`resource_amount` = NULL,
  tile.`npc_level` = IF(seed.`site_type` = 'gateway', 10, NULL),
  tile.`npc_garrison` = IF(
    existing_site.`site_id` IS NULL AND tile.`owner_id` IS NULL,
    seed.`npc_garrison`,
    tile.`npc_garrison`
  );

INSERT INTO `world_sites`
(`tile_id`,`site_code`,`site_type`,`display_name`,`max_durability`,`durability`,`owner_id`)
SELECT
  assigned.`tile_id`,
  seed.`site_code`,
  seed.`site_type`,
  seed.`display_name`,
  seed.`max_durability`,
  seed.`max_durability`,
  tile.`owner_id`
FROM `fireseed_world_site_seed` AS seed
JOIN `fireseed_world_site_assignment` AS assigned
  ON assigned.`site_code` = seed.`site_code`
JOIN `map_tiles` AS tile ON tile.`tile_id` = assigned.`tile_id`
ON DUPLICATE KEY UPDATE
  `tile_id` = VALUES(`tile_id`),
  `site_type` = VALUES(`site_type`),
  `display_name` = VALUES(`display_name`),
  `max_durability` = VALUES(`max_durability`),
  `durability` = LEAST(`durability`, VALUES(`max_durability`)),
  `owner_id` = VALUES(`owner_id`);

-- 其余旧随机银白标记恢复为普通领地或与其城市/受管地点一致，确保不存在“假银白之孔”。 / Restore every other legacy Silver marker to an ordinary territory or its city/managed-site state so no fake Silver Hole remains.
UPDATE `map_tiles` AS tile
LEFT JOIN `world_sites` AS managed_site
  ON managed_site.`tile_id` = tile.`tile_id`
LEFT JOIN `cities` AS city
  ON city.`x` = tile.`x` AND city.`y` = tile.`y`
SET
  tile.`type` = CASE
    WHEN city.`city_id` IS NOT NULL THEN 'player_city'
    WHEN managed_site.`site_type` = 'gateway' THEN 'npc_fort'
    ELSE 'empty'
  END,
  tile.`subtype` = CASE
    WHEN managed_site.`site_type` = 'gateway' THEN 'gateway'
    ELSE NULL
  END,
  tile.`owner_id` = CASE
    WHEN city.`city_id` IS NOT NULL THEN city.`owner_id`
    ELSE tile.`owner_id`
  END,
  tile.`resource_amount` = NULL,
  tile.`npc_level` = CASE
    WHEN managed_site.`site_type` = 'gateway' THEN COALESCE(tile.`npc_level`, 10)
    ELSE NULL
  END,
  tile.`npc_garrison` = CASE
    WHEN managed_site.`site_type` = 'gateway' THEN tile.`npc_garrison`
    ELSE 0
  END
WHERE tile.`subtype` = 'silver_hole'
  AND (
    managed_site.`site_code` IS NULL
    OR managed_site.`site_code` <> 'silver_hole'
  );

COMMIT;

DROP TEMPORARY TABLE `fireseed_world_site_assignment`;
DROP TEMPORARY TABLE `fireseed_world_site_seed`;

CREATE TABLE IF NOT EXISTS `seasons` (
  `season_id` int(11) NOT NULL AUTO_INCREMENT,
  `season_number` int(11) NOT NULL,
  `status` enum('active','victory_countdown','won','reset_pending') NOT NULL DEFAULT 'active',
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `winner_id` int(11) DEFAULT NULL,
  `victory_at` datetime DEFAULT NULL,
  `reset_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  PRIMARY KEY (`season_id`),
  UNIQUE KEY `season_number` (`season_number`),
  KEY `status` (`status`),
  CONSTRAINT `seasons_ibfk_1` FOREIGN KEY (`winner_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `season_scores` (
  `season_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `territory_score` int(11) NOT NULL DEFAULT 0,
  `battle_score` int(11) NOT NULL DEFAULT 0,
  `gateway_score` int(11) NOT NULL DEFAULT 0,
  `raid_score` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`season_id`,`user_id`),
  CONSTRAINT `season_scores_ibfk_1` FOREIGN KEY (`season_id`) REFERENCES `seasons` (`season_id`) ON DELETE CASCADE,
  CONSTRAINT `season_scores_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `territory_garrisons` (
  `garrison_id` int(11) NOT NULL AUTO_INCREMENT,
  `tile_id` int(11) NOT NULL,
  `owner_id` int(11) NOT NULL,
  `soldier_type` enum('pawn','knight','rook','bishop','golem','scout') NOT NULL,
  `level` int(11) NOT NULL DEFAULT 1,
  `quantity` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`garrison_id`),
  UNIQUE KEY `tile_unit` (`tile_id`,`soldier_type`,`level`),
  KEY `owner_id` (`owner_id`),
  CONSTRAINT `territory_garrisons_ibfk_1` FOREIGN KEY (`tile_id`) REFERENCES `map_tiles` (`tile_id`) ON DELETE CASCADE,
  CONSTRAINT `territory_garrisons_ibfk_2` FOREIGN KEY (`owner_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 侦察任务与报告独立于战斗记录，防守方不会收到侦察来袭提示。 / Scouting missions and reports stay separate from battles, so defenders receive no incoming-scout alert.
CREATE TABLE IF NOT EXISTS `scouting_missions` (
  `mission_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `army_id` int(11) DEFAULT NULL,
  `target_tile_id` int(11) DEFAULT NULL,
  `target_owner_id` int(11) DEFAULT NULL,
  `status` enum('launched','succeeded','failed') NOT NULL DEFAULT 'launched',
  `outcome` enum('success','failure') DEFAULT NULL,
  `report_json` text DEFAULT NULL,
  `launched_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `arrival_at` datetime NOT NULL,
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`mission_id`),
  KEY `user_launched` (`user_id`,`launched_at`),
  KEY `status_arrival` (`status`,`arrival_at`),
  KEY `army_status` (`army_id`,`status`),
  KEY `target_owner_id` (`target_owner_id`),
  CONSTRAINT `scouting_missions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `scouting_missions_ibfk_2` FOREIGN KEY (`army_id`) REFERENCES `armies` (`army_id`) ON DELETE SET NULL,
  CONSTRAINT `scouting_missions_ibfk_3` FOREIGN KEY (`target_tile_id`) REFERENCES `map_tiles` (`tile_id`) ON DELETE SET NULL,
  CONSTRAINT `scouting_missions_ibfk_4` FOREIGN KEY (`target_owner_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `battle_participants` (
  `battle_id` int(11) NOT NULL,
  `attacker_user_id` int(11) NOT NULL,
  `defender_user_id` int(11) DEFAULT NULL,
  `attacker_power` bigint(20) NOT NULL DEFAULT 0,
  `defender_power` bigint(20) NOT NULL DEFAULT 0,
  `counter_details` text DEFAULT NULL,
  PRIMARY KEY (`battle_id`),
  KEY `attacker_user_id` (`attacker_user_id`),
  KEY `defender_user_id` (`defender_user_id`),
  CONSTRAINT `battle_participants_ibfk_1` FOREIGN KEY (`battle_id`) REFERENCES `battles` (`battle_id`) ON DELETE CASCADE,
  CONSTRAINT `battle_participants_ibfk_2` FOREIGN KEY (`attacker_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `battle_participants_ibfk_3` FOREIGN KEY (`defender_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 为旧已结算战报保存可可靠恢复的参与方；城池与领地旧防守方无法从易主后的行恢复，故不错误授权当前领主。 / Snapshot reliably recoverable participants for legacy resolved reports; post-capture city and tile rows cannot identify the old defender, so the current owner is deliberately not authorized.
INSERT IGNORE INTO `battle_participants`
(`battle_id`,`attacker_user_id`,`defender_user_id`,`attacker_power`,`defender_power`,`counter_details`)
SELECT
  b.`battle_id`,
  attacker_army.`owner_id`,
  defender_army.`owner_id`,
  COALESCE(b.`attacker_power_snapshot`, 0),
  0,
  NULL
FROM `battles` b
INNER JOIN `armies` attacker_army
  ON attacker_army.`army_id` = b.`attacker_army_id`
LEFT JOIN `armies` defender_army
  ON defender_army.`army_id` = b.`defender_army_id`
WHERE b.`result` <> 'pending';

CREATE TABLE IF NOT EXISTS `prisoners` (
  `prisoner_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `owner_id` int(11) NOT NULL,
  `source_user_id` int(11) DEFAULT NULL,
  `battle_id` int(11) DEFAULT NULL,
  `soldier_type` enum('pawn','knight','rook','bishop','golem') NOT NULL,
  `level` int(11) NOT NULL DEFAULT 1,
  `quantity` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`prisoner_id`),
  KEY `owner_id` (`owner_id`),
  CONSTRAINT `prisoners_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `prisoners_ibfk_2` FOREIGN KEY (`source_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `prisoners_ibfk_3` FOREIGN KEY (`battle_id`) REFERENCES `battles` (`battle_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `gameplay_events` (
  `event_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `event_value` int(11) NOT NULL DEFAULT 1,
  `reference_type` varchar(32) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`),
  KEY `user_type_created` (`user_id`,`event_type`,`created_at`),
  CONSTRAINT `gameplay_events_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `quest_definitions` (
  `quest_id` int(11) NOT NULL AUTO_INCREMENT,
  `quest_code` varchar(64) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `target_value` int(11) NOT NULL,
  `reset_cycle` enum('none','daily','weekly') NOT NULL DEFAULT 'none',
  `reward_json` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`quest_id`),
  UNIQUE KEY `quest_code` (`quest_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_quests` (
  `user_quest_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `quest_id` int(11) NOT NULL,
  `period_key` varchar(16) NOT NULL DEFAULT 'lifetime',
  `progress` int(11) NOT NULL DEFAULT 0,
  `status` enum('active','completed','claimed') NOT NULL DEFAULT 'active',
  `claimed_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_quest_id`),
  UNIQUE KEY `user_quest_period` (`user_id`,`quest_id`,`period_key`),
  CONSTRAINT `user_quests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `user_quests_ibfk_2` FOREIGN KEY (`quest_id`) REFERENCES `quest_definitions` (`quest_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `achievement_definitions` (
  `achievement_id` int(11) NOT NULL AUTO_INCREMENT,
  `achievement_code` varchar(64) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `target_value` int(11) NOT NULL,
  `reward_json` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`achievement_id`),
  UNIQUE KEY `achievement_code` (`achievement_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_achievements` (
  `user_id` int(11) NOT NULL,
  `achievement_id` int(11) NOT NULL,
  `progress` int(11) NOT NULL DEFAULT 0,
  `unlocked_at` datetime DEFAULT NULL,
  `claimed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`,`achievement_id`),
  CONSTRAINT `user_achievements_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `user_achievements_ibfk_2` FOREIGN KEY (`achievement_id`) REFERENCES `achievement_definitions` (`achievement_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `raid_events` (
  `raid_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `max_hp` bigint(20) NOT NULL,
  `current_hp` bigint(20) NOT NULL,
  `defense_power` bigint(20) NOT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `status` enum('scheduled','active','defeated','ended') NOT NULL DEFAULT 'scheduled',
  `reward_json` text NOT NULL,
  PRIMARY KEY (`raid_id`),
  KEY `status_window` (`status`,`starts_at`,`ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `raid_participation` (
  `raid_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `total_damage` bigint(20) NOT NULL DEFAULT 0,
  `attack_count` int(11) NOT NULL DEFAULT 0,
  `last_attack_at` datetime DEFAULT NULL,
  `reward_claimed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`raid_id`,`user_id`),
  CONSTRAINT `raid_participation_ibfk_1` FOREIGN KEY (`raid_id`) REFERENCES `raid_events` (`raid_id`) ON DELETE CASCADE,
  CONSTRAINT `raid_participation_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `arena_profiles` (
  `user_id` int(11) NOT NULL,
  `defense_army_id` int(11) DEFAULT NULL,
  `rating` int(11) NOT NULL DEFAULT 1000,
  `wins` int(11) NOT NULL DEFAULT 0,
  `losses` int(11) NOT NULL DEFAULT 0,
  `season_points` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  KEY `rating` (`rating`),
  CONSTRAINT `arena_profiles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `arena_profiles_ibfk_2` FOREIGN KEY (`defense_army_id`) REFERENCES `armies` (`army_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `arena_battles` (
  `arena_battle_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `attacker_id` int(11) NOT NULL,
  `defender_id` int(11) NOT NULL,
  `attacker_army_id` int(11) DEFAULT NULL,
  `defender_army_id` int(11) DEFAULT NULL,
  `attacker_power` bigint(20) NOT NULL,
  `defender_power` bigint(20) NOT NULL,
  `winner_id` int(11) DEFAULT NULL,
  `rating_change` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`arena_battle_id`),
  KEY `attacker_created` (`attacker_id`,`created_at`),
  KEY `defender_created` (`defender_id`,`created_at`),
  CONSTRAINT `arena_battles_ibfk_1` FOREIGN KEY (`attacker_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `arena_battles_ibfk_2` FOREIGN KEY (`defender_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `arena_battles_ibfk_3` FOREIGN KEY (`attacker_army_id`) REFERENCES `armies` (`army_id`) ON DELETE SET NULL,
  CONSTRAINT `arena_battles_ibfk_4` FOREIGN KEY (`defender_army_id`) REFERENCES `armies` (`army_id`) ON DELETE SET NULL,
  CONSTRAINT `arena_battles_ibfk_5` FOREIGN KEY (`winner_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 平局没有赢家；同步旧扩展表的可空性与删除策略。 / Draws have no winner; align nullability and delete behavior on older expansion tables.
SET @fireseed_arena_winner_fk_parts = (
  SELECT GROUP_CONCAT(CONCAT('DROP FOREIGN KEY `', constraint_name, '`') SEPARATOR ', ')
  FROM information_schema.key_column_usage
  WHERE table_schema = DATABASE()
    AND table_name = 'arena_battles'
    AND column_name = 'winner_id'
    AND referenced_table_name IS NOT NULL
);
SET @fireseed_arena_winner_fk_sql = IF(
  @fireseed_arena_winner_fk_parts IS NULL,
  'DO 0',
  CONCAT('ALTER TABLE `arena_battles` ', @fireseed_arena_winner_fk_parts)
);
PREPARE fireseed_stmt FROM @fireseed_arena_winner_fk_sql;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

ALTER TABLE `arena_battles` MODIFY COLUMN `winner_id` int(11) DEFAULT NULL;
ALTER TABLE `arena_battles`
  ADD CONSTRAINT `arena_battles_ibfk_5`
  FOREIGN KEY (`winner_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS `tower_progress` (
  `user_id` int(11) NOT NULL,
  `current_floor` int(11) NOT NULL DEFAULT 1,
  `highest_floor` int(11) NOT NULL DEFAULT 0,
  `attempts_today` int(11) NOT NULL DEFAULT 0,
  `attempt_date` date DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `tower_progress_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `messages` (
  `message_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `sender_id` int(11) DEFAULT NULL,
  `receiver_id` int(11) NOT NULL,
  `subject` varchar(120) NOT NULL,
  `body` text NOT NULL,
  `message_type` enum('player','system','battle','reward') NOT NULL DEFAULT 'player',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `sent_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`message_id`),
  KEY `receiver_read_sent` (`receiver_id`,`is_read`,`sent_at`),
  KEY `sender_id` (`sender_id`),
  CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_messages` (
  `chat_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `sender_id` int(11) DEFAULT NULL,
  `channel_type` enum('world','alliance','private') NOT NULL,
  `channel_id` int(11) DEFAULT NULL,
  `body` varchar(500) NOT NULL,
  `sent_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`chat_id`),
  KEY `channel_sent` (`channel_type`,`channel_id`,`sent_at`),
  KEY `sender_id` (`sender_id`),
  CONSTRAINT `chat_messages_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `friendships` (
  `friendship_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `requester_id` int(11) NOT NULL,
  `addressee_id` int(11) NOT NULL,
  `status` enum('pending','accepted','blocked','rejected') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`friendship_id`),
  UNIQUE KEY `request_pair` (`requester_id`,`addressee_id`),
  KEY `addressee_status` (`addressee_id`,`status`),
  CONSTRAINT `friendships_ibfk_1` FOREIGN KEY (`requester_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `friendships_ibfk_2` FOREIGN KEY (`addressee_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 可重复执行的初始目录与玩法种子。 / Rerunnable initial catalogs and gameplay seeds.
INSERT IGNORE INTO `skill_card_catalog`
(`card_code`,`name`,`description`,`rarity`,`element`,`activation_type`,`category`,`effect_json`,`base_cooldown`,`max_level`) VALUES
('training_acceleration_basic','士兵训练加速・初','小幅缩短驻扎城池的士兵训练时间。','B','亮晶晶','passive','internal','{"training_speed":5}',0,5),
('lightning_march_basic','闪电行军・初','短时间提高所属军队的移动速度。','B','暖洋洋','active','march','{"speed":15,"duration":180}',7200,5),
('iron_wall_basic','铁壁防御・初','短时间提高武将与所属士兵的守备力。','B','冷冰冰','active','defense','{"defense":15,"duration":180}',7200,5),
('battle_burst_basic','战斗爆发・初','短时间提高武将与所属士兵的攻击力。','B','郁萌萌','active','attack','{"attack":15,"duration":180}',7200,5),
('healing_basic','治疗・初','恢复少量武将生命值，发动后进入冷却。','B','昼闪闪','active','support','{"healing":10}',3600,5),
('scout_enhancement_basic','侦察强化・初','小幅提高侦察兵的侦察能力。','B','夜静静','passive','special','{"scout_range":5}',0,5),
('resource_acceleration','资源加速','提高驻扎城池的全部资源产量。','A','亮晶晶','passive','internal','{"production":10}',0,5),
('training_acceleration','士兵训练加速','缩短驻扎城池的士兵训练时间。','A','亮晶晶','passive','internal','{"training_speed":10}',0,5),
('resource_burst','资源爆发','立即获得六色资源，发动后进入冷却。','S','亮晶晶','active','support','{"all_resources":1000}',3600,5),
('march_acceleration','行军加速','提高所属军队的移动速度。','A','暖洋洋','passive','march','{"speed":10}',0,5),
('lightning_march','闪电行军','短时间大幅提高所属军队的移动速度。','S','暖洋洋','active','march','{"speed":30,"duration":300}',7200,5),
('attack_enhancement','攻击强化','提高武将与所属士兵的攻击力。','A','郁萌萌','passive','attack','{"attack":10}',0,5),
('battle_burst','战斗爆发','短时间大幅提高武将与所属士兵的攻击力。','S','郁萌萌','active','attack','{"attack":30,"duration":300}',7200,5),
('defense_enhancement','防御强化','提高武将与所属士兵的守备力。','A','冷冰冰','passive','defense','{"defense":10}',0,5),
('iron_wall','铁壁防御','短时间大幅提高武将与所属士兵的守备力。','S','冷冰冰','active','defense','{"defense":30,"duration":300}',7200,5),
('healing','治疗','恢复武将生命值，发动后进入冷却。','A','昼闪闪','active','support','{"healing":20}',3600,5),
('scout_enhancement','侦察强化','提高侦察兵的侦察能力。','A','夜静静','passive','special','{"scout_range":10}',0,5),
('white_hole_resonance','银白共鸣','提高全军攻击、守备与速度。','P','夜静静','passive','special','{"attack":15,"defense":15,"speed":10}',0,5),
('crystal_guard','晶障','降低所属军队受到的战损。','SS','冷冰冰','passive','defense','{"damage_reduction":15}',0,5),
('data_insight','数据洞察','提高技能效果与任务奖励。','SS','亮晶晶','passive','support','{"skill_power":15,"quest_reward":5}',0,5);

-- 从企划武将设计文档同步 G001-G014，并添加六张低罕贵同角色版本以支持 B 池。 / Synchronize G001-G014 from the project design and add six lower-rarity versions for the B pool.
DROP TEMPORARY TABLE IF EXISTS `fireseed_general_template_seed`;
CREATE TEMPORARY TABLE `fireseed_general_template_seed` (
  `template_code` varchar(16) NOT NULL,
  `name` varchar(100) NOT NULL,
  `rarity` enum('B','A','S','SS','P') NOT NULL,
  `cost` decimal(3,1) NOT NULL,
  `element` enum('亮晶晶','暖洋洋','冷冰冰','郁萌萌','昼闪闪','夜静静') NOT NULL,
  `attack` int(11) NOT NULL,
  `defense` int(11) NOT NULL,
  `speed` int(11) NOT NULL,
  `intelligence` int(11) NOT NULL,
  `skill_card_code` varchar(64) NOT NULL,
  PRIMARY KEY (`template_code`)
) ENGINE=InnoDB;

INSERT INTO `fireseed_general_template_seed`
(`template_code`,`name`,`rarity`,`cost`,`element`,`attack`,`defense`,`speed`,`intelligence`,`skill_card_code`) VALUES
('G001','白银之主','S',3.0,'亮晶晶',20,80,50,100,'resource_acceleration'),
('G002','晶光使者','A',2.0,'亮晶晶',15,60,40,80,'training_acceleration'),
('G003','炎之剑客','S',3.0,'暖洋洋',100,20,80,50,'march_acceleration'),
('G004','烈火战士','A',2.0,'暖洋洋',80,15,60,40,'lightning_march'),
('G005','冰霜守护者','S',3.0,'冷冰冰',50,100,20,50,'defense_enhancement'),
('G006','寒冰战士','A',2.0,'冷冰冰',40,80,15,40,'iron_wall'),
('G007','森林之王','S',3.0,'郁萌萌',100,20,80,50,'attack_enhancement'),
('G008','翠绿射手','A',2.0,'郁萌萌',80,15,60,40,'battle_burst'),
('G009','太阳神使','S',3.0,'昼闪闪',20,50,80,100,'healing'),
('G010','光明祭司','A',2.0,'昼闪闪',15,40,60,80,'healing'),
('G011','暗影大师','S',3.0,'夜静静',20,80,50,100,'scout_enhancement'),
('G012','夜行者','A',2.0,'夜静静',15,60,40,80,'scout_enhancement'),
('G013','数据之王','SS',3.5,'亮晶晶',30,90,60,120,'resource_burst'),
('G014','银白之孔守护者','P',4.0,'夜静静',40,100,70,150,'scout_enhancement'),
('G002B','晶光使者','B',1.0,'亮晶晶',10,45,30,60,'training_acceleration_basic'),
('G004B','烈火战士','B',1.0,'暖洋洋',60,10,45,30,'lightning_march_basic'),
('G006B','寒冰战士','B',1.0,'冷冰冰',30,60,10,30,'iron_wall_basic'),
('G008B','翠绿射手','B',1.0,'郁萌萌',60,10,45,30,'battle_burst_basic'),
('G010B','光明祭司','B',1.0,'昼闪闪',10,30,45,60,'healing_basic'),
('G012B','夜行者','B',1.0,'夜静静',10,45,30,60,'scout_enhancement_basic');

INSERT INTO `generals`
(`owner_id`,`name`,`source`,`rarity`,`cost`,`element`,`level`,`hp`,`max_hp`,`attack`,`defense`,`speed`,`intelligence`,`is_active`)
SELECT
  0, seed.`name`, '原创角色', seed.`rarity`, seed.`cost`, seed.`element`,
  1, 100, 100, seed.`attack`, seed.`defense`, seed.`speed`, seed.`intelligence`, 1
FROM `fireseed_general_template_seed` AS seed
LEFT JOIN `general_template_catalog` AS catalog
  ON catalog.`template_code` = seed.`template_code`
LEFT JOIN `generals` AS existing
  ON existing.`owner_id` = 0
  AND existing.`name` = seed.`name`
  AND existing.`source` = '原创角色'
  AND existing.`rarity` = seed.`rarity`
WHERE catalog.`template_code` IS NULL
  AND existing.`general_id` IS NULL;

INSERT IGNORE INTO `general_template_catalog` (`template_code`,`general_id`)
SELECT seed.`template_code`, MIN(general.`general_id`)
FROM `fireseed_general_template_seed` AS seed
JOIN `generals` AS general
  ON general.`owner_id` = 0
  AND general.`name` = seed.`name`
  AND general.`source` = '原创角色'
  AND general.`rarity` = seed.`rarity`
GROUP BY seed.`template_code`;

UPDATE `generals` AS general
JOIN `general_template_catalog` AS catalog
  ON catalog.`general_id` = general.`general_id`
JOIN `fireseed_general_template_seed` AS seed
  ON seed.`template_code` = catalog.`template_code`
SET
  general.`owner_id` = 0,
  general.`name` = seed.`name`,
  general.`source` = '原创角色',
  general.`rarity` = seed.`rarity`,
  general.`cost` = seed.`cost`,
  general.`element` = seed.`element`,
  general.`level` = 1,
  general.`hp` = 100,
  general.`max_hp` = 100,
  general.`attack` = seed.`attack`,
  general.`defense` = seed.`defense`,
  general.`speed` = seed.`speed`,
  general.`intelligence` = seed.`intelligence`,
  general.`is_active` = 1;

UPDATE `general_skills` AS skill
JOIN `general_template_catalog` AS catalog
  ON catalog.`general_id` = skill.`general_id`
JOIN `fireseed_general_template_seed` AS seed
  ON seed.`template_code` = catalog.`template_code`
JOIN `skill_card_catalog` AS card
  ON card.`card_code` = seed.`skill_card_code`
SET
  skill.`skill_type` = '自带',
  skill.`skill_name` = card.`name`,
  skill.`skill_level` = 1,
  skill.`skill_effect` = card.`effect_json`
WHERE skill.`slot` = 0;

INSERT INTO `general_skills`
(`general_id`,`skill_type`,`skill_name`,`slot`,`skill_level`,`skill_effect`)
SELECT
  catalog.`general_id`, '自带', card.`name`, 0, 1, card.`effect_json`
FROM `general_template_catalog` AS catalog
JOIN `fireseed_general_template_seed` AS seed
  ON seed.`template_code` = catalog.`template_code`
JOIN `skill_card_catalog` AS card
  ON card.`card_code` = seed.`skill_card_code`
LEFT JOIN `general_skills` AS existing
  ON existing.`general_id` = catalog.`general_id`
  AND existing.`slot` = 0
WHERE existing.`skill_id` IS NULL;

INSERT INTO `equipped_skill_cards` (`skill_id`,`card_id`,`equipped_at`)
SELECT skill.`skill_id`, card.`card_id`, NOW()
FROM `general_template_catalog` AS catalog
JOIN `fireseed_general_template_seed` AS seed
  ON seed.`template_code` = catalog.`template_code`
JOIN `skill_card_catalog` AS card
  ON card.`card_code` = seed.`skill_card_code`
JOIN `general_skills` AS skill
  ON skill.`general_id` = catalog.`general_id`
  AND skill.`slot` = 0
ON DUPLICATE KEY UPDATE
  `card_id` = VALUES(`card_id`);

DROP TEMPORARY TABLE `fireseed_general_template_seed`;

INSERT IGNORE INTO `shop_catalog`
(`item_code`,`name`,`description`,`cost_json`,`grant_json`,`daily_limit`) VALUES
('break_core','蜕变核心','用于武将 BREAK 的通用素材。','{"bright":3000,"night":500}','{"items":{"break_core":1}}',5),
('skill_notes','技能札记','获得用于技能升级的技能点。','{"night":800}','{"wallet":{"skill_points":10}}',10),
('field_rations','战地补给','补充六色基础资源。','{"bright":1000}','{"resources":{"warm":1000,"cold":1000,"green":1000,"day":1000}}',5),
('circuit_fragment','回路碎片','获得一点思考回路。','{"bright":5000,"night":1000}','{"circuit_points":1}',1),
('arena_supply','竞技补给','使用竞技场代币兑换蜕变核心。','{"arena_tokens":50}','{"items":{"break_core":1}}',3),
('merit_supply','功勋补给','使用战斗功勋兑换前线资源。','{"merit_points":25}','{"resources":{"bright":1000,"night":300}}',5);

INSERT IGNORE INTO `quest_definitions`
(`quest_code`,`name`,`description`,`event_type`,`target_value`,`reset_cycle`,`reward_json`) VALUES
('daily_recruit','今日契约','今日完成一次武将招募。','general_recruited',1,'daily','{"resources":{"bright":500},"wallet":{"skill_points":2}}'),
('daily_skill_draw','夜色寻卡','今日抽取一次技能卡。','skill_card_drawn',1,'daily','{"resources":{"night":250},"wallet":{"skill_points":2}}'),
('daily_battle','边界巡弋','今日完成一次战斗。','battle_completed',1,'daily','{"resources":{"warm":300,"cold":300,"green":300,"day":300},"wallet":{"merit_points":5}}'),
('weekly_territory','拓界者','本周占领五块领地。','territory_captured',5,'weekly','{"circuit_points":1,"items":{"break_core":1}}'),
('main_gateway','十二门试炼','占领任意一座 NPC 主城。','gateway_captured',1,'none','{"resources":{"bright":10000,"night":3000},"wallet":{"skill_points":20}}'),
('main_tower','数据高塔','通过战斗之塔第十层。','tower_floor_cleared',10,'none','{"items":{"break_core":2},"wallet":{"arena_tokens":50}}');

INSERT IGNORE INTO `achievement_definitions`
(`achievement_code`,`name`,`description`,`event_type`,`target_value`,`reward_json`) VALUES
('first_contract','最初的契约','获得第一名武将。','general_recruited',1,'{"resources":{"bright":1000}}'),
('collector_10','十人阵列','累计获得十名武将。','general_recruited',10,'{"items":{"break_core":1}}'),
('territory_25','数据拓荒者','累计占领二十五块领地。','territory_captured',25,'{"circuit_points":2}'),
('battle_wins_10','边界常胜','累计赢得十场战斗。','battle_won',10,'{"wallet":{"merit_points":100}}'),
('alliance_join','并肩钻探','加入一个联盟。','alliance_joined',1,'{"resources":{"bright":1000,"night":500}}'),
('break_once','第一次蜕变','完成一次武将 BREAK。','general_broken',1,'{"wallet":{"skill_points":20}}'),
('tower_25','塔上观海','通过战斗之塔第二十五层。','tower_floor_cleared',25,'{"items":{"break_core":3}}'),
('raid_hero','潮汐破阵','在讨伐战累计造成十万伤害。','raid_damage',100000,'{"wallet":{"arena_tokens":100}}');

INSERT IGNORE INTO `seasons` (`season_number`,`status`,`started_at`)
VALUES (1,'active',NOW());

INSERT INTO `raid_events`
(`name`,`description`,`max_hp`,`current_hp`,`defense_power`,`starts_at`,`ends_at`,`status`,`reward_json`)
SELECT
  '数据潮汐·零号',
  '来自数据海深层的周期性聚合体。所有钻探者均可贡献伤害。',
  1000000,
  1000000,
  500,
  NOW(),
  DATE_ADD(NOW(), INTERVAL 7 DAY),
  'active',
  '{"resources":{"bright":2000,"night":500},"items":{"break_core":1}}'
WHERE NOT EXISTS (
  SELECT 1 FROM `raid_events` WHERE `name` = '数据潮汐·零号'
);

-- 合并完成后该值必须为零，且上方已无条件补齐唯一索引。 / This must be zero after merging, and the unique index above is always enforced.
SELECT @fireseed_resource_duplicate_count AS `duplicate_resource_users`;

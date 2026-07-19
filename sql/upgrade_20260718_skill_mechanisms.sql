-- 种火集结号 - 技能机制第二版与原作基础技能种子 / Fireseed Engage - skill-mechanism schema v2 and source base-skill seed
-- 旧服升级须依次先执行 upgrade_20260717_gameplay_expansion.sql 与 upgrade_20260717_card_pool_resources.sql；本文件同时由全新安装器调用，并可安全重复执行。 / Legacy upgrades must first run upgrade_20260717_gameplay_expansion.sql and then upgrade_20260717_card_pool_resources.sql; the fresh installer also invokes this file, and it is safe to rerun.
SET SESSION time_zone = '+08:00';

-- 只在旧字段不足100字符时扩宽；保留运营方自行设置的更宽字段。 / Widen only legacy columns below 100 characters, preserving any wider operator-defined column.
SET @fireseed_skill_name_length = (
  SELECT `character_maximum_length`
  FROM `information_schema`.`columns`
  WHERE `table_schema` = DATABASE()
    AND `table_name` = 'general_skills'
    AND `column_name` = 'skill_name'
  LIMIT 1
);
SET @fireseed_ddl = IF(
  @fireseed_skill_name_length IS NULL
    OR @fireseed_skill_name_length >= 100,
  'DO 0',
  'ALTER TABLE `general_skills` MODIFY COLUMN `skill_name` varchar(100) NOT NULL'
);
PREPARE fireseed_stmt FROM @fireseed_ddl;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

-- 生产状态使用独立游标，并在逐设施快照中同时保存最终率和无技能基础率；普通资源增减不会抹掉生产余数。 / Production state uses an independent cursor and stores both final and unskilled base rates per facility, so ordinary resource changes do not erase production remainders.
-- 首边界相对游标偏移、末边界dirty时间、变化次数、观察窗口及独立计划状态可识别端点相同或延迟到期的多次变更；单边界按tick起点精确结算，多边界不确定区段只支付未变化设施的共同基础率。 / A first-boundary cursor offset, latest dirty timestamp, change count, observation window, and independent schedule state identify repeated equal-endpoint or delayed transitions; a single boundary settles exactly by tick start, while a multi-change uncertain segment pays only unchanged facilities' common base rate.
CREATE TABLE IF NOT EXISTS `resource_production_states` (
  `user_id` int(11) NOT NULL,
  `settled_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dirty_since_offset_seconds` int unsigned DEFAULT NULL,
  `dirty_at` datetime DEFAULT NULL,
  `change_count` int unsigned NOT NULL DEFAULT 0,
  `change_window_observed` tinyint(1) NOT NULL DEFAULT 0,
  `scheduled_offset_seconds` int unsigned DEFAULT NULL,
  `scheduled_change_count` int unsigned NOT NULL DEFAULT 0,
  `snapshot_json` mediumtext DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `resource_production_states_ibfk_1`
    FOREIGN KEY (`user_id`) REFERENCES `resources` (`user_id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 上一版已创建状态表的服务器需要可重跑补列；相对偏移会随settled_at的赛季平移自动平移首边界。 / Servers that already created the previous state table need rerunnable column additions; the relative offset shifts the first boundary automatically when a season shifts settled_at.
SET @fireseed_has_dirty_since_offset = (
  SELECT COUNT(*)
  FROM `information_schema`.`columns`
  WHERE `table_schema` = DATABASE()
    AND `table_name` = 'resource_production_states'
    AND `column_name` = 'dirty_since_offset_seconds'
);
SET @fireseed_ddl = IF(
  @fireseed_has_dirty_since_offset > 0,
  'DO 0',
  'ALTER TABLE `resource_production_states` ADD COLUMN `dirty_since_offset_seconds` int unsigned DEFAULT NULL AFTER `settled_at`'
);
PREPARE fireseed_stmt FROM @fireseed_ddl;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

SET @fireseed_has_change_count = (
  SELECT COUNT(*)
  FROM `information_schema`.`columns`
  WHERE `table_schema` = DATABASE()
    AND `table_name` = 'resource_production_states'
    AND `column_name` = 'change_count'
);
SET @fireseed_ddl = IF(
  @fireseed_has_change_count > 0,
  'DO 0',
  'ALTER TABLE `resource_production_states` ADD COLUMN `change_count` int unsigned NOT NULL DEFAULT 0 AFTER `dirty_at`'
);
PREPARE fireseed_stmt FROM @fireseed_ddl;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

SET @fireseed_has_change_window_observed = (
  SELECT COUNT(*)
  FROM `information_schema`.`columns`
  WHERE `table_schema` = DATABASE()
    AND `table_name` = 'resource_production_states'
    AND `column_name` = 'change_window_observed'
);
SET @fireseed_ddl = IF(
  @fireseed_has_change_window_observed > 0,
  'DO 0',
  'ALTER TABLE `resource_production_states` ADD COLUMN `change_window_observed` tinyint(1) NOT NULL DEFAULT 0 AFTER `change_count`'
);
PREPARE fireseed_stmt FROM @fireseed_ddl;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

SET @fireseed_has_scheduled_offset = (
  SELECT COUNT(*)
  FROM `information_schema`.`columns`
  WHERE `table_schema` = DATABASE()
    AND `table_name` = 'resource_production_states'
    AND `column_name` = 'scheduled_offset_seconds'
);
SET @fireseed_ddl = IF(
  @fireseed_has_scheduled_offset > 0,
  'DO 0',
  'ALTER TABLE `resource_production_states` ADD COLUMN `scheduled_offset_seconds` int unsigned DEFAULT NULL AFTER `change_window_observed`'
);
PREPARE fireseed_stmt FROM @fireseed_ddl;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

SET @fireseed_has_scheduled_change_count = (
  SELECT COUNT(*)
  FROM `information_schema`.`columns`
  WHERE `table_schema` = DATABASE()
    AND `table_name` = 'resource_production_states'
    AND `column_name` = 'scheduled_change_count'
);
SET @fireseed_ddl = IF(
  @fireseed_has_scheduled_change_count > 0,
  'DO 0',
  'ALTER TABLE `resource_production_states` ADD COLUMN `scheduled_change_count` int unsigned NOT NULL DEFAULT 0 AFTER `scheduled_offset_seconds`'
);
PREPARE fireseed_stmt FROM @fireseed_ddl;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

-- 兼容上一版把计划边界放入dirty_at的状态；v1快照仍会由PHP安全重建v2基线。 / Convert the previous version's scheduled dirty_at state; PHP still rebuilds every v1 snapshot as a safe v2 baseline.
UPDATE `resource_production_states`
SET `scheduled_offset_seconds` = GREATEST(
      0,
      TIMESTAMPDIFF(SECOND, `settled_at`, `dirty_at`)
    ),
    `scheduled_change_count` = 1,
    `dirty_at` = NULL
WHERE `change_count` = 0
  AND `dirty_at` IS NOT NULL
  AND `scheduled_offset_seconds` IS NULL;

-- 旧服首次运行只建立当前基线，不追授无法证明的历史技能倍率。 / A legacy server's first run establishes a current baseline without backfilling unverifiable historical skill multipliers.
INSERT IGNORE INTO `resource_production_states`
(`user_id`,`settled_at`,`dirty_since_offset_seconds`,`dirty_at`,`change_count`,`change_window_observed`,`scheduled_offset_seconds`,`scheduled_change_count`,`snapshot_json`)
SELECT `user_id`, NOW(), NULL, NULL, 0, 0, NULL, 0, NULL
FROM `resources`;

-- 下列触发器均为无BEGIN块的单语句，兼容安装器、MySQL与MariaDB；重跑先删除同名触发器。 / The following triggers are single statements without BEGIN blocks for installer, MySQL, and MariaDB compatibility; reruns drop each named trigger first.
-- 城池与设施结构变化按两次变化记账，因为一次行变更可能同时引入尚未捕获的未来生效边界；其余实际变化按一次累加。 / City and facility structural changes count as two because one row mutation may also introduce an uncaptured future-effective boundary; other actual changes add one.
DROP TRIGGER IF EXISTS `fireseed_prod_resources_ai`;
CREATE TRIGGER `fireseed_prod_resources_ai`
AFTER INSERT ON `resources`
FOR EACH ROW
INSERT IGNORE INTO `resource_production_states`
(`user_id`,`settled_at`,`dirty_since_offset_seconds`,`dirty_at`,`change_count`,`change_window_observed`,`scheduled_offset_seconds`,`scheduled_change_count`,`snapshot_json`)
VALUES (NEW.`user_id`, NOW(), NULL, NULL, 0, 0, NULL, 0, NULL);

DROP TRIGGER IF EXISTS `fireseed_prod_cities_ai`;
CREATE TRIGGER `fireseed_prod_cities_ai`
AFTER INSERT ON `cities`
FOR EACH ROW
INSERT INTO `resource_production_states`
(`user_id`,`settled_at`,`dirty_since_offset_seconds`,`dirty_at`,`change_count`,`snapshot_json`)
SELECT resource.`user_id`, NOW(), 0, NOW(), 2, NULL
FROM `resources` AS resource
WHERE resource.`user_id` = NEW.`owner_id`
ON DUPLICATE KEY UPDATE
`dirty_since_offset_seconds` = IF(`change_count` = 0 OR `dirty_since_offset_seconds` IS NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, `settled_at`, VALUES(`dirty_at`))), `dirty_since_offset_seconds`),
`dirty_at` = VALUES(`dirty_at`),
`change_window_observed` = 0,
`change_count` = IF(`change_count` >= 4294967295 - VALUES(`change_count`), 4294967295, `change_count` + VALUES(`change_count`));

DROP TRIGGER IF EXISTS `fireseed_prod_cities_bu`;
CREATE TRIGGER `fireseed_prod_cities_bu`
BEFORE UPDATE ON `cities`
FOR EACH ROW
INSERT INTO `resource_production_states`
(`user_id`,`settled_at`,`dirty_since_offset_seconds`,`dirty_at`,`change_count`,`snapshot_json`)
SELECT resource.`user_id`, NOW(), 0, NOW(), 2, NULL
FROM `resources` AS resource
WHERE resource.`user_id` IN (OLD.`owner_id`, NEW.`owner_id`)
  AND NOT (OLD.`owner_id` <=> NEW.`owner_id`)
ON DUPLICATE KEY UPDATE
`dirty_since_offset_seconds` = IF(`change_count` = 0 OR `dirty_since_offset_seconds` IS NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, `settled_at`, VALUES(`dirty_at`))), `dirty_since_offset_seconds`),
`dirty_at` = VALUES(`dirty_at`),
`change_window_observed` = 0,
`change_count` = IF(`change_count` >= 4294967295 - VALUES(`change_count`), 4294967295, `change_count` + VALUES(`change_count`));

DROP TRIGGER IF EXISTS `fireseed_prod_cities_bd`;
CREATE TRIGGER `fireseed_prod_cities_bd`
BEFORE DELETE ON `cities`
FOR EACH ROW
INSERT INTO `resource_production_states`
(`user_id`,`settled_at`,`dirty_since_offset_seconds`,`dirty_at`,`change_count`,`snapshot_json`)
SELECT resource.`user_id`, NOW(), 0, NOW(), 2, NULL
FROM `resources` AS resource
WHERE resource.`user_id` = OLD.`owner_id`
ON DUPLICATE KEY UPDATE
`dirty_since_offset_seconds` = IF(`change_count` = 0 OR `dirty_since_offset_seconds` IS NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, `settled_at`, VALUES(`dirty_at`))), `dirty_since_offset_seconds`),
`dirty_at` = VALUES(`dirty_at`),
`change_window_observed` = 0,
`change_count` = IF(`change_count` >= 4294967295 - VALUES(`change_count`), 4294967295, `change_count` + VALUES(`change_count`));

DROP TRIGGER IF EXISTS `fireseed_prod_facilities_ai`;
CREATE TRIGGER `fireseed_prod_facilities_ai`
AFTER INSERT ON `facilities`
FOR EACH ROW
INSERT INTO `resource_production_states`
(`user_id`,`settled_at`,`dirty_since_offset_seconds`,`dirty_at`,`change_count`,`snapshot_json`)
SELECT resource.`user_id`, NOW(), 0, NOW(), 2, NULL
FROM `cities` AS city
JOIN `resources` AS resource ON resource.`user_id` = city.`owner_id`
WHERE city.`city_id` = NEW.`city_id`
ON DUPLICATE KEY UPDATE
`dirty_since_offset_seconds` = IF(`change_count` = 0 OR `dirty_since_offset_seconds` IS NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, `settled_at`, VALUES(`dirty_at`))), `dirty_since_offset_seconds`),
`dirty_at` = VALUES(`dirty_at`),
`change_window_observed` = 0,
`change_count` = IF(`change_count` >= 4294967295 - VALUES(`change_count`), 4294967295, `change_count` + VALUES(`change_count`));

DROP TRIGGER IF EXISTS `fireseed_prod_facilities_bu`;
CREATE TRIGGER `fireseed_prod_facilities_bu`
BEFORE UPDATE ON `facilities`
FOR EACH ROW
INSERT INTO `resource_production_states`
(`user_id`,`settled_at`,`dirty_since_offset_seconds`,`dirty_at`,`change_count`,`snapshot_json`)
SELECT DISTINCT resource.`user_id`, NOW(), 0, NOW(), 2, NULL
FROM `cities` AS city
JOIN `resources` AS resource ON resource.`user_id` = city.`owner_id`
WHERE city.`city_id` IN (OLD.`city_id`, NEW.`city_id`)
  AND (
    NOT (OLD.`city_id` <=> NEW.`city_id`)
    OR NOT (OLD.`type` <=> NEW.`type`)
    OR NOT (OLD.`subtype` <=> NEW.`subtype`)
    OR NOT (OLD.`level` <=> NEW.`level`)
    OR NOT (OLD.`construction_time` <=> NEW.`construction_time`)
    OR NOT (OLD.`upgrade_time` <=> NEW.`upgrade_time`)
  )
ON DUPLICATE KEY UPDATE
`dirty_since_offset_seconds` = IF(`change_count` = 0 OR `dirty_since_offset_seconds` IS NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, `settled_at`, VALUES(`dirty_at`))), `dirty_since_offset_seconds`),
`dirty_at` = VALUES(`dirty_at`),
`change_window_observed` = 0,
`change_count` = IF(`change_count` >= 4294967295 - VALUES(`change_count`), 4294967295, `change_count` + VALUES(`change_count`));

DROP TRIGGER IF EXISTS `fireseed_prod_facilities_bd`;
CREATE TRIGGER `fireseed_prod_facilities_bd`
BEFORE DELETE ON `facilities`
FOR EACH ROW
INSERT INTO `resource_production_states`
(`user_id`,`settled_at`,`dirty_since_offset_seconds`,`dirty_at`,`change_count`,`snapshot_json`)
SELECT resource.`user_id`, NOW(), 0, NOW(), 2, NULL
FROM `cities` AS city
JOIN `resources` AS resource ON resource.`user_id` = city.`owner_id`
WHERE city.`city_id` = OLD.`city_id`
ON DUPLICATE KEY UPDATE
`dirty_since_offset_seconds` = IF(`change_count` = 0 OR `dirty_since_offset_seconds` IS NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, `settled_at`, VALUES(`dirty_at`))), `dirty_since_offset_seconds`),
`dirty_at` = VALUES(`dirty_at`),
`change_window_observed` = 0,
`change_count` = IF(`change_count` >= 4294967295 - VALUES(`change_count`), 4294967295, `change_count` + VALUES(`change_count`));

DROP TRIGGER IF EXISTS `fireseed_prod_generals_bu`;
CREATE TRIGGER `fireseed_prod_generals_bu`
BEFORE UPDATE ON `generals`
FOR EACH ROW
INSERT INTO `resource_production_states`
(`user_id`,`settled_at`,`dirty_since_offset_seconds`,`dirty_at`,`change_count`,`snapshot_json`)
SELECT resource.`user_id`, NOW(), 0, NOW(), 1, NULL
FROM `resources` AS resource
WHERE resource.`user_id` IN (OLD.`owner_id`, NEW.`owner_id`)
  AND (
    NOT (OLD.`owner_id` <=> NEW.`owner_id`)
    OR NOT (OLD.`cost` <=> NEW.`cost`)
    OR NOT (OLD.`level` <=> NEW.`level`)
    OR NOT (OLD.`hp` <=> NEW.`hp`)
    OR NOT (OLD.`max_hp` <=> NEW.`max_hp`)
    OR NOT (OLD.`attack` <=> NEW.`attack`)
    OR NOT (OLD.`defense` <=> NEW.`defense`)
    OR NOT (OLD.`speed` <=> NEW.`speed`)
    OR NOT (OLD.`intelligence` <=> NEW.`intelligence`)
    OR NOT (OLD.`is_active` <=> NEW.`is_active`)
  )
ON DUPLICATE KEY UPDATE
`dirty_since_offset_seconds` = IF(`change_count` = 0 OR `dirty_since_offset_seconds` IS NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, `settled_at`, VALUES(`dirty_at`))), `dirty_since_offset_seconds`),
`dirty_at` = VALUES(`dirty_at`),
`change_window_observed` = 0,
`change_count` = IF(`change_count` >= 4294967295 - VALUES(`change_count`), 4294967295, `change_count` + VALUES(`change_count`));

DROP TRIGGER IF EXISTS `fireseed_prod_generals_bd`;
CREATE TRIGGER `fireseed_prod_generals_bd`
BEFORE DELETE ON `generals`
FOR EACH ROW
INSERT INTO `resource_production_states`
(`user_id`,`settled_at`,`dirty_since_offset_seconds`,`dirty_at`,`change_count`,`snapshot_json`)
SELECT resource.`user_id`, NOW(), 0, NOW(), 1, NULL
FROM `resources` AS resource
WHERE resource.`user_id` = OLD.`owner_id`
ON DUPLICATE KEY UPDATE
`dirty_since_offset_seconds` = IF(`change_count` = 0 OR `dirty_since_offset_seconds` IS NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, `settled_at`, VALUES(`dirty_at`))), `dirty_since_offset_seconds`),
`dirty_at` = VALUES(`dirty_at`),
`change_window_observed` = 0,
`change_count` = IF(`change_count` >= 4294967295 - VALUES(`change_count`), 4294967295, `change_count` + VALUES(`change_count`));

DROP TRIGGER IF EXISTS `fireseed_prod_assignments_ai`;
CREATE TRIGGER `fireseed_prod_assignments_ai`
AFTER INSERT ON `general_assignments`
FOR EACH ROW
INSERT INTO `resource_production_states`
(`user_id`,`settled_at`,`dirty_since_offset_seconds`,`dirty_at`,`change_count`,`snapshot_json`)
SELECT resource.`user_id`, NOW(), 0, NOW(), 1, NULL
FROM `generals` AS general
JOIN `resources` AS resource ON resource.`user_id` = general.`owner_id`
WHERE general.`general_id` = NEW.`general_id`
ON DUPLICATE KEY UPDATE
`dirty_since_offset_seconds` = IF(`change_count` = 0 OR `dirty_since_offset_seconds` IS NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, `settled_at`, VALUES(`dirty_at`))), `dirty_since_offset_seconds`),
`dirty_at` = VALUES(`dirty_at`),
`change_window_observed` = 0,
`change_count` = IF(`change_count` >= 4294967295 - VALUES(`change_count`), 4294967295, `change_count` + VALUES(`change_count`));

DROP TRIGGER IF EXISTS `fireseed_prod_assignments_bu`;
CREATE TRIGGER `fireseed_prod_assignments_bu`
BEFORE UPDATE ON `general_assignments`
FOR EACH ROW
INSERT INTO `resource_production_states`
(`user_id`,`settled_at`,`dirty_since_offset_seconds`,`dirty_at`,`change_count`,`snapshot_json`)
SELECT DISTINCT resource.`user_id`, NOW(), 0, NOW(), 1, NULL
FROM `generals` AS general
JOIN `resources` AS resource ON resource.`user_id` = general.`owner_id`
WHERE general.`general_id` IN (OLD.`general_id`, NEW.`general_id`)
  AND (
    NOT (OLD.`general_id` <=> NEW.`general_id`)
    OR NOT (OLD.`assignment_type` <=> NEW.`assignment_type`)
    OR NOT (OLD.`target_id` <=> NEW.`target_id`)
  )
ON DUPLICATE KEY UPDATE
`dirty_since_offset_seconds` = IF(`change_count` = 0 OR `dirty_since_offset_seconds` IS NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, `settled_at`, VALUES(`dirty_at`))), `dirty_since_offset_seconds`),
`dirty_at` = VALUES(`dirty_at`),
`change_window_observed` = 0,
`change_count` = IF(`change_count` >= 4294967295 - VALUES(`change_count`), 4294967295, `change_count` + VALUES(`change_count`));

DROP TRIGGER IF EXISTS `fireseed_prod_assignments_bd`;
CREATE TRIGGER `fireseed_prod_assignments_bd`
BEFORE DELETE ON `general_assignments`
FOR EACH ROW
INSERT INTO `resource_production_states`
(`user_id`,`settled_at`,`dirty_since_offset_seconds`,`dirty_at`,`change_count`,`snapshot_json`)
SELECT resource.`user_id`, NOW(), 0, NOW(), 1, NULL
FROM `generals` AS general
JOIN `resources` AS resource ON resource.`user_id` = general.`owner_id`
WHERE general.`general_id` = OLD.`general_id`
ON DUPLICATE KEY UPDATE
`dirty_since_offset_seconds` = IF(`change_count` = 0 OR `dirty_since_offset_seconds` IS NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, `settled_at`, VALUES(`dirty_at`))), `dirty_since_offset_seconds`),
`dirty_at` = VALUES(`dirty_at`),
`change_window_observed` = 0,
`change_count` = IF(`change_count` >= 4294967295 - VALUES(`change_count`), 4294967295, `change_count` + VALUES(`change_count`));

DROP TRIGGER IF EXISTS `fireseed_prod_general_skills_ai`;
CREATE TRIGGER `fireseed_prod_general_skills_ai`
AFTER INSERT ON `general_skills`
FOR EACH ROW
INSERT INTO `resource_production_states`
(`user_id`,`settled_at`,`dirty_since_offset_seconds`,`dirty_at`,`change_count`,`snapshot_json`)
SELECT resource.`user_id`, NOW(), 0, NOW(), 1, NULL
FROM `generals` AS general
JOIN `resources` AS resource ON resource.`user_id` = general.`owner_id`
WHERE general.`general_id` = NEW.`general_id`
ON DUPLICATE KEY UPDATE
`dirty_since_offset_seconds` = IF(`change_count` = 0 OR `dirty_since_offset_seconds` IS NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, `settled_at`, VALUES(`dirty_at`))), `dirty_since_offset_seconds`),
`dirty_at` = VALUES(`dirty_at`),
`change_window_observed` = 0,
`change_count` = IF(`change_count` >= 4294967295 - VALUES(`change_count`), 4294967295, `change_count` + VALUES(`change_count`));

DROP TRIGGER IF EXISTS `fireseed_prod_general_skills_bu`;
CREATE TRIGGER `fireseed_prod_general_skills_bu`
BEFORE UPDATE ON `general_skills`
FOR EACH ROW
INSERT INTO `resource_production_states`
(`user_id`,`settled_at`,`dirty_since_offset_seconds`,`dirty_at`,`change_count`,`snapshot_json`)
SELECT DISTINCT resource.`user_id`, NOW(), 0, NOW(), 1, NULL
FROM `generals` AS general
JOIN `resources` AS resource ON resource.`user_id` = general.`owner_id`
WHERE general.`general_id` IN (OLD.`general_id`, NEW.`general_id`)
  AND (
    NOT (OLD.`general_id` <=> NEW.`general_id`)
    OR NOT (OLD.`skill_type` <=> NEW.`skill_type`)
    OR NOT (OLD.`slot` <=> NEW.`slot`)
    OR NOT (OLD.`skill_level` <=> NEW.`skill_level`)
    OR NOT (OLD.`skill_effect` <=> NEW.`skill_effect`)
  )
ON DUPLICATE KEY UPDATE
`dirty_since_offset_seconds` = IF(`change_count` = 0 OR `dirty_since_offset_seconds` IS NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, `settled_at`, VALUES(`dirty_at`))), `dirty_since_offset_seconds`),
`dirty_at` = VALUES(`dirty_at`),
`change_window_observed` = 0,
`change_count` = IF(`change_count` >= 4294967295 - VALUES(`change_count`), 4294967295, `change_count` + VALUES(`change_count`));

DROP TRIGGER IF EXISTS `fireseed_prod_general_skills_bd`;
CREATE TRIGGER `fireseed_prod_general_skills_bd`
BEFORE DELETE ON `general_skills`
FOR EACH ROW
INSERT INTO `resource_production_states`
(`user_id`,`settled_at`,`dirty_since_offset_seconds`,`dirty_at`,`change_count`,`snapshot_json`)
SELECT resource.`user_id`, NOW(), 0, NOW(), 1, NULL
FROM `generals` AS general
JOIN `resources` AS resource ON resource.`user_id` = general.`owner_id`
WHERE general.`general_id` = OLD.`general_id`
ON DUPLICATE KEY UPDATE
`dirty_since_offset_seconds` = IF(`change_count` = 0 OR `dirty_since_offset_seconds` IS NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, `settled_at`, VALUES(`dirty_at`))), `dirty_since_offset_seconds`),
`dirty_at` = VALUES(`dirty_at`),
`change_window_observed` = 0,
`change_count` = IF(`change_count` >= 4294967295 - VALUES(`change_count`), 4294967295, `change_count` + VALUES(`change_count`));

DROP TRIGGER IF EXISTS `fireseed_prod_mappings_ai`;
CREATE TRIGGER `fireseed_prod_mappings_ai`
AFTER INSERT ON `equipped_skill_cards`
FOR EACH ROW
INSERT INTO `resource_production_states`
(`user_id`,`settled_at`,`dirty_since_offset_seconds`,`dirty_at`,`change_count`,`snapshot_json`)
SELECT resource.`user_id`, NOW(), 0, NOW(), 1, NULL
FROM `general_skills` AS skill
JOIN `generals` AS general ON general.`general_id` = skill.`general_id`
JOIN `resources` AS resource ON resource.`user_id` = general.`owner_id`
WHERE skill.`skill_id` = NEW.`skill_id`
ON DUPLICATE KEY UPDATE
`dirty_since_offset_seconds` = IF(`change_count` = 0 OR `dirty_since_offset_seconds` IS NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, `settled_at`, VALUES(`dirty_at`))), `dirty_since_offset_seconds`),
`dirty_at` = VALUES(`dirty_at`),
`change_window_observed` = 0,
`change_count` = IF(`change_count` >= 4294967295 - VALUES(`change_count`), 4294967295, `change_count` + VALUES(`change_count`));

DROP TRIGGER IF EXISTS `fireseed_prod_mappings_bu`;
CREATE TRIGGER `fireseed_prod_mappings_bu`
BEFORE UPDATE ON `equipped_skill_cards`
FOR EACH ROW
INSERT INTO `resource_production_states`
(`user_id`,`settled_at`,`dirty_since_offset_seconds`,`dirty_at`,`change_count`,`snapshot_json`)
SELECT DISTINCT resource.`user_id`, NOW(), 0, NOW(), 1, NULL
FROM `general_skills` AS skill
JOIN `generals` AS general ON general.`general_id` = skill.`general_id`
JOIN `resources` AS resource ON resource.`user_id` = general.`owner_id`
WHERE skill.`skill_id` IN (OLD.`skill_id`, NEW.`skill_id`)
  AND (
    NOT (OLD.`skill_id` <=> NEW.`skill_id`)
    OR NOT (OLD.`card_id` <=> NEW.`card_id`)
  )
ON DUPLICATE KEY UPDATE
`dirty_since_offset_seconds` = IF(`change_count` = 0 OR `dirty_since_offset_seconds` IS NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, `settled_at`, VALUES(`dirty_at`))), `dirty_since_offset_seconds`),
`dirty_at` = VALUES(`dirty_at`),
`change_window_observed` = 0,
`change_count` = IF(`change_count` >= 4294967295 - VALUES(`change_count`), 4294967295, `change_count` + VALUES(`change_count`));

DROP TRIGGER IF EXISTS `fireseed_prod_mappings_bd`;
CREATE TRIGGER `fireseed_prod_mappings_bd`
BEFORE DELETE ON `equipped_skill_cards`
FOR EACH ROW
INSERT INTO `resource_production_states`
(`user_id`,`settled_at`,`dirty_since_offset_seconds`,`dirty_at`,`change_count`,`snapshot_json`)
SELECT resource.`user_id`, NOW(), 0, NOW(), 1, NULL
FROM `general_skills` AS skill
JOIN `generals` AS general ON general.`general_id` = skill.`general_id`
JOIN `resources` AS resource ON resource.`user_id` = general.`owner_id`
WHERE skill.`skill_id` = OLD.`skill_id`
ON DUPLICATE KEY UPDATE
`dirty_since_offset_seconds` = IF(`change_count` = 0 OR `dirty_since_offset_seconds` IS NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, `settled_at`, VALUES(`dirty_at`))), `dirty_since_offset_seconds`),
`dirty_at` = VALUES(`dirty_at`),
`change_window_observed` = 0,
`change_count` = IF(`change_count` >= 4294967295 - VALUES(`change_count`), 4294967295, `change_count` + VALUES(`change_count`));

DROP TRIGGER IF EXISTS `fireseed_prod_catalog_bu`;
CREATE TRIGGER `fireseed_prod_catalog_bu`
BEFORE UPDATE ON `skill_card_catalog`
FOR EACH ROW
INSERT INTO `resource_production_states`
(`user_id`,`settled_at`,`dirty_since_offset_seconds`,`dirty_at`,`change_count`,`snapshot_json`)
SELECT DISTINCT resource.`user_id`, NOW(), 0, NOW(), 1, NULL
FROM `equipped_skill_cards` AS equipped
JOIN `general_skills` AS skill ON skill.`skill_id` = equipped.`skill_id`
JOIN `generals` AS general ON general.`general_id` = skill.`general_id`
JOIN `resources` AS resource ON resource.`user_id` = general.`owner_id`
WHERE equipped.`card_id` = OLD.`card_id`
  AND (
    NOT (OLD.`effect_json` <=> NEW.`effect_json`)
    OR NOT (OLD.`activation_type` <=> NEW.`activation_type`)
    OR NOT (OLD.`max_level` <=> NEW.`max_level`)
    OR NOT (OLD.`is_active` <=> NEW.`is_active`)
  )
ON DUPLICATE KEY UPDATE
`dirty_since_offset_seconds` = IF(`change_count` = 0 OR `dirty_since_offset_seconds` IS NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, `settled_at`, VALUES(`dirty_at`))), `dirty_since_offset_seconds`),
`dirty_at` = VALUES(`dirty_at`),
`change_window_observed` = 0,
`change_count` = IF(`change_count` >= 4294967295 - VALUES(`change_count`), 4294967295, `change_count` + VALUES(`change_count`));

DROP TRIGGER IF EXISTS `fireseed_prod_catalog_bd`;
CREATE TRIGGER `fireseed_prod_catalog_bd`
BEFORE DELETE ON `skill_card_catalog`
FOR EACH ROW
INSERT INTO `resource_production_states`
(`user_id`,`settled_at`,`dirty_since_offset_seconds`,`dirty_at`,`change_count`,`snapshot_json`)
SELECT DISTINCT resource.`user_id`, NOW(), 0, NOW(), 1, NULL
FROM `equipped_skill_cards` AS equipped
JOIN `general_skills` AS skill ON skill.`skill_id` = equipped.`skill_id`
JOIN `generals` AS general ON general.`general_id` = skill.`general_id`
JOIN `resources` AS resource ON resource.`user_id` = general.`owner_id`
WHERE equipped.`card_id` = OLD.`card_id`
ON DUPLICATE KEY UPDATE
`dirty_since_offset_seconds` = IF(`change_count` = 0 OR `dirty_since_offset_seconds` IS NULL, GREATEST(0, TIMESTAMPDIFF(SECOND, `settled_at`, VALUES(`dirty_at`))), `dirty_since_offset_seconds`),
`dirty_at` = VALUES(`dirty_at`),
`change_window_observed` = 0,
`change_count` = IF(`change_count` >= 4294967295 - VALUES(`change_count`), 4294967295, `change_count` + VALUES(`change_count`));

-- 独立完成标记让原作技能种子与旧资源目录种子互不影响，并阻止重跑恢复被运营方改动或移除的卡。 / An independent completion marker decouples this source-skill seed from older catalog seeds and prevents reruns from restoring operator-edited or retired cards.
START TRANSACTION;

SELECT `lock_name`
FROM `resource_admin_locks`
WHERE `lock_name` = 'catalog_pools'
FOR UPDATE;

SELECT `config_id`
FROM `game_config`
WHERE `key` = 'me_skill_mechanism_seed_20260718'
FOR UPDATE;

DROP TEMPORARY TABLE IF EXISTS `fireseed_me_skill_seed_gate`;
CREATE TEMPORARY TABLE `fireseed_me_skill_seed_gate` (
  `allowed` tinyint(1) NOT NULL,
  PRIMARY KEY (`allowed`)
) ENGINE=InnoDB;

INSERT INTO `fireseed_me_skill_seed_gate` (`allowed`)
SELECT 1
WHERE NOT EXISTS (
  SELECT 1
  FROM `game_config`
  WHERE `key` = 'me_skill_mechanism_seed_20260718'
);

DROP TEMPORARY TABLE IF EXISTS `fireseed_me_skill_seed`;
CREATE TEMPORARY TABLE `fireseed_me_skill_seed` LIKE `skill_card_catalog`;

-- 原作CT按“小时:分钟”转换为秒，只写入cooldown曲线和base_cooldown，绝不推测为持续时间。 / Source CT is converted from HH:MM to seconds and stored only in cooldown curves and base_cooldown, never inferred as effect duration.
-- 原作四势力按大地→郁萌萌、太陽→昼闪闪、星→亮晶晶、月→夜静静映射；未凭空补造暖洋洋或冷冰冰变体。 / The four source factions map as Earth→Green, Sun→Day, Star→Bright, and Moon→Night; no Warm or Cold variants are invented.
INSERT INTO `fireseed_me_skill_seed`
(`card_code`,`name`,`description`,`rarity`,`element`,`activation_type`,`category`,`effect_json`,`base_cooldown`,`max_level`) VALUES
('me_rook_morale','剣士の士気','原作ルーク类型映射为本作城壁；按武将COST与技能等级提高城壁攻击力。','B','冷冰冰','passive','attack','{"schema_version":2,"application_mode":"continuous","effects":[{"mechanism":"army_stat_percent","parameters":{"stat":"attack","unit_type":"rook"},"value":{"mode":"cost_level_values","values":[4,5,6,7,8,9,10,11,12.5,14]},"conditions":[]}]}',0,10),
('me_bishop_morale','司祭の士気','原作ビショップ类型映射为本作主教；按武将COST与技能等级提高主教攻击力。','B','亮晶晶','passive','attack','{"schema_version":2,"application_mode":"continuous","effects":[{"mechanism":"army_stat_percent","parameters":{"stat":"attack","unit_type":"bishop"},"value":{"mode":"cost_level_values","values":[4,5,6,7,8,9,10,11,12.5,14]},"conditions":[]}]}',0,10),
('me_knight_morale','騎士の士気','原作ナイト类型映射为本作骑士；按武将COST与技能等级提高骑士攻击力。','B','暖洋洋','passive','attack','{"schema_version":2,"application_mode":"continuous","effects":[{"mechanism":"army_stat_percent","parameters":{"stat":"attack","unit_type":"knight"},"value":{"mode":"cost_level_values","values":[4,5,6,7,8,9,10,11,12.5,14]},"conditions":[]}]}',0,10),
('me_march_expertise','疾駆の心得','按武将COST与技能等级持续提高全军移动速度。','B','暖洋洋','passive','march','{"schema_version":2,"application_mode":"continuous","effects":[{"mechanism":"army_stat_percent","parameters":{"stat":"speed","unit_type":"all"},"value":{"mode":"cost_level_values","values":[2.6,3.3,3.9,4.6,5.2,5.9,6.5,7.2,8.1,9.1]},"conditions":[]}]}',0,10),
('me_earth_morale','大地の士気','每名同队郁萌萌武将按技能等级提高全军攻击力；对应原作大地势力。','B','郁萌萌','passive','attack','{"schema_version":2,"application_mode":"continuous","effects":[{"mechanism":"army_element_stat_percent","parameters":{"element":"green","stat":"attack"},"value":{"mode":"level_values","values":[2.1,2.5,2.9,3.4,3.8,4.3,4.7,5.3,5.9,6.5]},"conditions":[]}]}',0,10),
('me_sun_morale','太陽の士気','每名同队昼闪闪武将按技能等级提高全军攻击力；对应原作太陽势力。','B','昼闪闪','passive','attack','{"schema_version":2,"application_mode":"continuous","effects":[{"mechanism":"army_element_stat_percent","parameters":{"element":"day","stat":"attack"},"value":{"mode":"level_values","values":[2.1,2.5,2.9,3.4,3.8,4.3,4.7,5.3,5.9,6.5]},"conditions":[]}]}',0,10),
('me_star_morale','星の士気','每名同队亮晶晶武将按技能等级提高全军攻击力；对应原作星势力。','B','亮晶晶','passive','attack','{"schema_version":2,"application_mode":"continuous","effects":[{"mechanism":"army_element_stat_percent","parameters":{"element":"bright","stat":"attack"},"value":{"mode":"level_values","values":[2.1,2.5,2.9,3.4,3.8,4.3,4.7,5.3,5.9,6.5]},"conditions":[]}]}',0,10),
('me_moon_morale','月の士気','每名同队夜静静武将按技能等级提高全军攻击力；对应原作月势力。','B','夜静静','passive','attack','{"schema_version":2,"application_mode":"continuous","effects":[{"mechanism":"army_element_stat_percent","parameters":{"element":"night","stat":"attack"},"value":{"mode":"level_values","values":[2.1,2.5,2.9,3.4,3.8,4.3,4.7,5.3,5.9,6.5]},"conditions":[]}]}',0,10),
('me_defense_preparation','防戦準備','驻城武将仅在城池防守战中按COST与技能等级提高最终城防。','B','冷冰冰','passive','defense','{"schema_version":2,"application_mode":"continuous","effects":[{"mechanism":"city_defense_percent","parameters":{},"value":{"mode":"cost_level_values","values":[1.5,2,2.5,3,3.5,4,4.5,5,6,7]},"conditions":[{"type":"side","operator":"eq","value":"defense"}]}]}',0,10),
('me_mana_baptism','マナの洗礼','发动时按武将COST与技能等级立即获得全部六色资源；原作“全部マナ”按本作资源体系移植。','S','郁萌萌','active','support','{"schema_version":2,"application_mode":"instant","cooldown":{"mode":"level_values","values":[259200,258600,258000,256800,255600,253800,251400,248400,244800,239400]},"effects":[{"mechanism":"grant_resources","parameters":{"resource":"all"},"value":{"mode":"cost_level_values","values":[2000,10000,18000,26000,36000,46000,56000,68000,80000,100000]},"conditions":[]}]}',259200,10),
('me_goddess_charity','女神の慈愛','发动时按技能等级恢复玩家持有的全部武将HP。','A','昼闪闪','active','support','{"schema_version":2,"application_mode":"instant","cooldown":{"mode":"level_values","values":[259200,258600,258000,256800,255600,253800,251400,248400,244800,239400]},"effects":[{"mechanism":"heal_generals","parameters":{"target":"all_owned"},"value":{"mode":"level_values","values":[3,5,7,9,11,13,15,17,20,23]},"conditions":[]}]}',259200,10),
('me_base_reinforcement','拠点増強','发动时按武将COST与技能等级恢复所属城池耐久。','A','冷冰冰','active','support','{"schema_version":2,"application_mode":"instant","cooldown":{"mode":"level_values","values":[172800,169200,165600,162000,158400,154800,151200,144000,136800,129600]},"effects":[{"mechanism":"repair_assigned_city","parameters":{},"value":{"mode":"cost_level_values","values":[4,6,8,11,14,18,23,29,36,44]},"conditions":[]}]}',172800,10),
('me_casting_reduction','詠唱短縮','发动时按技能等级缩短未分配武将技能的剩余冷却；本作以“未分配”适配原作“待机中”。','A','夜静静','active','support','{"schema_version":2,"application_mode":"instant","cooldown":{"mode":"level_values","values":[259200,258600,258000,256800,255600,253800,251400,248400,244800,239400]},"effects":[{"mechanism":"reduce_skill_cooldowns","parameters":{"target":"unassigned_owned"},"value":{"mode":"level_values","values":[1200,1500,1800,2100,2400,2700,3000,3600,4200,4800]},"conditions":[]}]}',259200,10);

INSERT INTO `skill_card_catalog`
(`card_code`,`name`,`description`,`rarity`,`element`,`activation_type`,`category`,`effect_json`,`base_cooldown`,`max_level`)
SELECT
  seed.`card_code`, seed.`name`, seed.`description`, seed.`rarity`,
  seed.`element`, seed.`activation_type`, seed.`category`,
  seed.`effect_json`, seed.`base_cooldown`, seed.`max_level`
FROM `fireseed_me_skill_seed` AS seed
JOIN `fireseed_me_skill_seed_gate` AS seed_gate
  ON seed_gate.`allowed` = 1
LEFT JOIN `skill_card_catalog` AS existing
  ON existing.`card_code` = seed.`card_code`
WHERE existing.`card_id` IS NULL;

DROP TEMPORARY TABLE `fireseed_me_skill_seed`;

INSERT INTO `game_config`
(`key`,`value`,`description`,`is_constant`,`category`)
SELECT
  'me_skill_mechanism_seed_20260718',
  'complete',
  '原作基础技能机制第二版种子已完成；用于阻止重跑覆盖运营配置 / Source base-skill mechanism-v2 seed completed; prevents reruns from overriding live configuration',
  1,
  'system'
FROM `fireseed_me_skill_seed_gate` AS seed_gate
WHERE seed_gate.`allowed` = 1;

DROP TEMPORARY TABLE `fireseed_me_skill_seed_gate`;
COMMIT;

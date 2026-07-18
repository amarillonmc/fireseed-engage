-- 种火集结号 - 2026-07-17 资源目录与卡池升级 / Fireseed Engage - 2026-07-17 catalog and card-pool upgrade
-- 先执行 upgrade_20260717_gameplay_expansion.sql；本文件可重复执行且不会覆盖已有卡池成员权重。 / Run upgrade_20260717_gameplay_expansion.sql first; this file is rerunnable and does not overwrite existing pool-entry weights.
-- 执行前请备份数据库，并先选择现有游戏数据库。DDL 会自动提交。 / Back up the database and select the existing game schema first. DDL auto-commits.

SET NAMES utf8mb4;
SET SESSION time_zone = '+08:00';

CREATE TABLE IF NOT EXISTS `card_pools` (
  `pool_id` int(11) NOT NULL AUTO_INCREMENT,
  `pool_code` varchar(64) NOT NULL,
  `pool_type` enum('general','skill') NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `cost_json` text NOT NULL,
  `allowed_counts_json` varchar(255) NOT NULL DEFAULT '[1]',
  `status` enum('draft','published','archived') NOT NULL DEFAULT 'draft',
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `revision` int(11) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`pool_id`),
  UNIQUE KEY `pool_code` (`pool_code`),
  KEY `type_status_schedule` (`pool_type`,`status`,`starts_at`,`ends_at`,`sort_order`),
  KEY `created_by` (`created_by`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `card_pools_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `card_pools_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `general_pool_entries` (
  `pool_id` int(11) NOT NULL,
  `general_id` int(11) NOT NULL,
  `weight` int(10) unsigned NOT NULL,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`pool_id`,`general_id`),
  KEY `general_id` (`general_id`),
  KEY `pool_featured_weight` (`pool_id`,`is_featured`,`weight`),
  CONSTRAINT `general_pool_entries_ibfk_1` FOREIGN KEY (`pool_id`) REFERENCES `card_pools` (`pool_id`) ON DELETE CASCADE,
  CONSTRAINT `general_pool_entries_ibfk_2` FOREIGN KEY (`general_id`) REFERENCES `generals` (`general_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `skill_pool_entries` (
  `pool_id` int(11) NOT NULL,
  `card_id` int(11) NOT NULL,
  `weight` int(10) unsigned NOT NULL,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`pool_id`,`card_id`),
  KEY `card_id` (`card_id`),
  KEY `pool_featured_weight` (`pool_id`,`is_featured`,`weight`),
  CONSTRAINT `skill_pool_entries_ibfk_1` FOREIGN KEY (`pool_id`) REFERENCES `card_pools` (`pool_id`) ON DELETE CASCADE,
  CONSTRAINT `skill_pool_entries_ibfk_2` FOREIGN KEY (`card_id`) REFERENCES `skill_card_catalog` (`card_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 资源目录与卡池后台共享一把事务互斥锁，避免发布、成员和稀有度变更互相穿插。 / Catalog and pool administration share one transactional mutex so publishing, membership, and rarity changes cannot interleave.
CREATE TABLE IF NOT EXISTS `resource_admin_locks` (
  `lock_name` varchar(64) NOT NULL,
  PRIMARY KEY (`lock_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `resource_admin_locks` (`lock_name`)
VALUES ('catalog_pools');

ALTER TABLE `general_template_catalog`
  MODIFY COLUMN `template_code` varchar(64) NOT NULL;

ALTER TABLE `recruitment_history`
  MODIFY COLUMN `recruit_type` enum('starter','normal','advanced','resonance','quest','event','pool') NOT NULL;

-- 条件补齐历史快照字段，避免重复执行时报“字段已存在”。 / Add history snapshot columns conditionally so reruns do not fail on duplicate columns.
SET @fireseed_columns = CONCAT_WS(', ',
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'recruitment_history' AND column_name = 'pool_id'), NULL, 'ADD COLUMN `pool_id` int(11) DEFAULT NULL AFTER `rarity`'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'recruitment_history' AND column_name = 'pool_code_snapshot'), NULL, 'ADD COLUMN `pool_code_snapshot` varchar(64) DEFAULT NULL AFTER `pool_id`'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'recruitment_history' AND column_name = 'pool_revision'), NULL, 'ADD COLUMN `pool_revision` int(11) DEFAULT NULL AFTER `pool_code_snapshot`'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'recruitment_history' AND column_name = 'entry_weight'), NULL, 'ADD COLUMN `entry_weight` int(10) unsigned DEFAULT NULL AFTER `pool_revision`'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'recruitment_history' AND column_name = 'total_weight'), NULL, 'ADD COLUMN `total_weight` bigint(20) unsigned DEFAULT NULL AFTER `entry_weight`'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'recruitment_history' AND column_name = 'cost_json'), NULL, 'ADD COLUMN `cost_json` text DEFAULT NULL AFTER `total_weight`')
);
SET @fireseed_ddl = IF(@fireseed_columns = '', 'DO 0', CONCAT('ALTER TABLE `recruitment_history` ', @fireseed_columns));
PREPARE fireseed_stmt FROM @fireseed_ddl;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

SET @fireseed_columns = CONCAT_WS(', ',
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'skill_draw_history' AND column_name = 'pool_id'), NULL, 'ADD COLUMN `pool_id` int(11) DEFAULT NULL AFTER `cost_night`'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'skill_draw_history' AND column_name = 'pool_code_snapshot'), NULL, 'ADD COLUMN `pool_code_snapshot` varchar(64) DEFAULT NULL AFTER `pool_id`'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'skill_draw_history' AND column_name = 'pool_revision'), NULL, 'ADD COLUMN `pool_revision` int(11) DEFAULT NULL AFTER `pool_code_snapshot`'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'skill_draw_history' AND column_name = 'entry_weight'), NULL, 'ADD COLUMN `entry_weight` int(10) unsigned DEFAULT NULL AFTER `pool_revision`'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'skill_draw_history' AND column_name = 'total_weight'), NULL, 'ADD COLUMN `total_weight` bigint(20) unsigned DEFAULT NULL AFTER `entry_weight`'),
  IF(EXISTS(SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'skill_draw_history' AND column_name = 'cost_json'), NULL, 'ADD COLUMN `cost_json` text DEFAULT NULL AFTER `total_weight`')
);
SET @fireseed_ddl = IF(@fireseed_columns = '', 'DO 0', CONCAT('ALTER TABLE `skill_draw_history` ', @fireseed_columns));
PREPARE fireseed_stmt FROM @fireseed_ddl;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

SET @fireseed_ddl = IF(
  EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'recruitment_history' AND index_name = 'pool_id'),
  'DO 0',
  'ALTER TABLE `recruitment_history` ADD KEY `pool_id` (`pool_id`)'
);
PREPARE fireseed_stmt FROM @fireseed_ddl;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

SET @fireseed_ddl = IF(
  EXISTS(SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'skill_draw_history' AND index_name = 'pool_id'),
  'DO 0',
  'ALTER TABLE `skill_draw_history` ADD KEY `pool_id` (`pool_id`)'
);
PREPARE fireseed_stmt FROM @fireseed_ddl;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

SET @fireseed_ddl = IF(
  EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'recruitment_history' AND constraint_name = 'recruitment_history_ibfk_4'),
  'DO 0',
  'ALTER TABLE `recruitment_history` ADD CONSTRAINT `recruitment_history_ibfk_4` FOREIGN KEY (`pool_id`) REFERENCES `card_pools` (`pool_id`) ON DELETE SET NULL'
);
PREPARE fireseed_stmt FROM @fireseed_ddl;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

SET @fireseed_ddl = IF(
  EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'skill_draw_history' AND constraint_name = 'skill_draw_history_ibfk_3'),
  'DO 0',
  'ALTER TABLE `skill_draw_history` ADD CONSTRAINT `skill_draw_history_ibfk_3` FOREIGN KEY (`pool_id`) REFERENCES `card_pools` (`pool_id`) ON DELETE SET NULL'
);
PREPARE fireseed_stmt FROM @fireseed_ddl;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

-- 抽取历史必须保留目录引用，和全新安装的RESTRICT语义保持一致。 / Draw history must retain its catalog reference, matching the fresh schema's RESTRICT semantics.
SET @fireseed_skill_history_delete_rule = (
  SELECT `delete_rule`
  FROM information_schema.referential_constraints
  WHERE constraint_schema = DATABASE()
    AND table_name = 'skill_draw_history'
    AND constraint_name = 'skill_draw_history_ibfk_2'
  LIMIT 1
);
SET @fireseed_ddl = IF(
  @fireseed_skill_history_delete_rule IS NOT NULL
    AND @fireseed_skill_history_delete_rule NOT IN ('RESTRICT', 'NO ACTION'),
  'ALTER TABLE `skill_draw_history` DROP FOREIGN KEY `skill_draw_history_ibfk_2`',
  'DO 0'
);
PREPARE fireseed_stmt FROM @fireseed_ddl;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

SET @fireseed_ddl = IF(
  EXISTS(SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'skill_draw_history' AND constraint_name = 'skill_draw_history_ibfk_2'),
  'DO 0',
  'ALTER TABLE `skill_draw_history` ADD CONSTRAINT `skill_draw_history_ibfk_2` FOREIGN KEY (`card_id`) REFERENCES `skill_card_catalog` (`card_id`) ON DELETE RESTRICT'
);
PREPARE fireseed_stmt FROM @fireseed_ddl;
EXECUTE fireseed_stmt;
DEALLOCATE PREPARE fireseed_stmt;

-- 目录与默认池升级种子作为一个事务执行，完成标记确保重跑不会恢复运营方已移除或修改的资源。 / Catalog and default-pool upgrade seeds run in one transaction; the completion marker prevents reruns from restoring operator-managed resources.
START TRANSACTION;

SELECT `config_id`
FROM `game_config`
WHERE `key` = 'resource_catalog_seed_20260717'
FOR UPDATE;

DROP TEMPORARY TABLE IF EXISTS `fireseed_resource_seed_gate`;
CREATE TEMPORARY TABLE `fireseed_resource_seed_gate` (
  `allowed` tinyint(1) NOT NULL,
  PRIMARY KEY (`allowed`)
) ENGINE=InnoDB;

INSERT INTO `fireseed_resource_seed_gate` (`allowed`)
SELECT 1
WHERE NOT EXISTS (
  SELECT 1
  FROM `game_config`
  WHERE `key` = 'resource_catalog_seed_20260717'
);

-- 移植原作可验证的等级曲线，但名称、元素与兵种均使用本项目虚构层。 / Adapt verified original level curves while keeping this project's names, elements, and unit types.
DROP TEMPORARY TABLE IF EXISTS `fireseed_skill_card_seed`;
CREATE TEMPORARY TABLE `fireseed_skill_card_seed` LIKE `skill_card_catalog`;

INSERT INTO `fireseed_skill_card_seed`
(`card_code`,`name`,`description`,`rarity`,`element`,`activation_type`,`category`,`effect_json`,`base_cooldown`,`max_level`) VALUES
('pawn_assault_doctrine','兵卒锐击','按武将COST与技能等级提高所属兵卒攻击力。','B','暖洋洋','passive','attack','{"unit_attack_pawn":{"mode":"cost_level_values","values":[8,11,13,16,19,22,26,30,35,40]}}',0,10),
('knight_assault_doctrine','骑士锐击','按武将COST与技能等级提高所属骑士攻击力。','B','暖洋洋','passive','attack','{"unit_attack_knight":{"mode":"cost_level_values","values":[8,11,13,16,19,22,26,30,35,40]}}',0,10),
('rook_assault_doctrine','城壁锐击','按武将COST与技能等级提高所属城壁攻击力。','B','冷冰冰','passive','attack','{"unit_attack_rook":{"mode":"cost_level_values","values":[8,11,13,16,19,22,26,30,35,40]}}',0,10),
('bishop_assault_doctrine','主教锐击','按武将COST与技能等级提高所属主教攻击力。','B','郁萌萌','passive','attack','{"unit_attack_bishop":{"mode":"cost_level_values","values":[8,11,13,16,19,22,26,30,35,40]}}',0,10),
('golem_assault_doctrine','锤兵锐击','按武将COST与技能等级提高所属锤子兵攻击力。','B','郁萌萌','passive','attack','{"unit_attack_golem":{"mode":"cost_level_values","values":[8,11,13,16,19,22,26,30,35,40]}}',0,10),
('scout_assault_doctrine','斥候锐击','按武将COST与技能等级提高所属侦察兵攻击力。','B','夜静静','passive','attack','{"unit_attack_scout":{"mode":"cost_level_values","values":[8,11,13,16,19,22,26,30,35,40]}}',0,10),
('pawn_march_doctrine','兵卒疾行','按武将COST与技能等级提高所属兵卒速度。','A','昼闪闪','passive','march','{"unit_speed_pawn":{"mode":"cost_level_values","values":[9,11,13,16,19,22,26,30,35,40]}}',0,10),
('knight_march_doctrine','骑士疾行','按武将COST与技能等级提高所属骑士速度。','A','暖洋洋','passive','march','{"unit_speed_knight":{"mode":"cost_level_values","values":[9,11,13,16,19,22,26,30,35,40]}}',0,10),
('rook_march_doctrine','城壁疾行','按武将COST与技能等级提高所属城壁速度。','A','冷冰冰','passive','march','{"unit_speed_rook":{"mode":"cost_level_values","values":[9,11,13,16,19,22,26,30,35,40]}}',0,10),
('bishop_march_doctrine','主教疾行','按武将COST与技能等级提高所属主教速度。','A','亮晶晶','passive','march','{"unit_speed_bishop":{"mode":"cost_level_values","values":[9,11,13,16,19,22,26,30,35,40]}}',0,10),
('golem_march_doctrine','锤兵疾行','按武将COST与技能等级提高所属锤子兵速度。','A','郁萌萌','passive','march','{"unit_speed_golem":{"mode":"cost_level_values","values":[9,11,13,16,19,22,26,30,35,40]}}',0,10),
('scout_march_doctrine','斥候疾行','按武将COST与技能等级提高所属侦察兵速度。','A','夜静静','passive','march','{"unit_speed_scout":{"mode":"cost_level_values","values":[9,11,13,16,19,22,26,30,35,40]}}',0,10),
('bright_resonance_assault','亮晶豪击','每名同队亮晶晶武将按技能等级提高全军攻击力，最多计算三名。','S','亮晶晶','passive','attack','{"element_attack_per_bright":{"mode":"level_values","values":[6,7,8,9,10,11,12,13,15,17]}}',0,10),
('warm_resonance_assault','暖洋豪击','每名同队暖洋洋武将按技能等级提高全军攻击力，最多计算三名。','S','暖洋洋','passive','attack','{"element_attack_per_warm":{"mode":"level_values","values":[6,7,8,9,10,11,12,13,15,17]}}',0,10),
('cold_resonance_assault','冷冰豪击','每名同队冷冰冰武将按技能等级提高全军攻击力，最多计算三名。','S','冷冰冰','passive','attack','{"element_attack_per_cold":{"mode":"level_values","values":[6,7,8,9,10,11,12,13,15,17]}}',0,10),
('green_resonance_assault','郁萌豪击','每名同队郁萌萌武将按技能等级提高全军攻击力，最多计算三名。','S','郁萌萌','passive','attack','{"element_attack_per_green":{"mode":"level_values","values":[6,7,8,9,10,11,12,13,15,17]}}',0,10),
('day_resonance_assault','昼闪豪击','每名同队昼闪闪武将按技能等级提高全军攻击力，最多计算三名。','S','昼闪闪','passive','attack','{"element_attack_per_day":{"mode":"level_values","values":[6,7,8,9,10,11,12,13,15,17]}}',0,10),
('night_resonance_assault','夜静豪击','每名同队夜静静武将按技能等级提高全军攻击力，最多计算三名。','S','夜静静','passive','attack','{"element_attack_per_night":{"mode":"level_values","values":[6,7,8,9,10,11,12,13,15,17]}}',0,10);

INSERT INTO `skill_card_catalog`
(`card_code`,`name`,`description`,`rarity`,`element`,`activation_type`,`category`,`effect_json`,`base_cooldown`,`max_level`)
SELECT
  seed.`card_code`, seed.`name`, seed.`description`, seed.`rarity`,
  seed.`element`, seed.`activation_type`, seed.`category`,
  seed.`effect_json`, seed.`base_cooldown`, seed.`max_level`
FROM `fireseed_skill_card_seed` AS seed
JOIN `fireseed_resource_seed_gate` AS seed_gate
  ON seed_gate.`allowed` = 1
LEFT JOIN `skill_card_catalog` AS existing
  ON existing.`card_code` = seed.`card_code`
WHERE existing.`card_id` IS NULL;

DROP TEMPORARY TABLE `fireseed_skill_card_seed`;

DROP TEMPORARY TABLE IF EXISTS `fireseed_card_pool_general_seed`;
CREATE TEMPORARY TABLE `fireseed_card_pool_general_seed` (
  `template_code` varchar(64) NOT NULL,
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

INSERT INTO `fireseed_card_pool_general_seed`
(`template_code`,`name`,`rarity`,`cost`,`element`,`attack`,`defense`,`speed`,`intelligence`,`skill_card_code`) VALUES
('G015','透镜书记','B',1.5,'亮晶晶',18,55,38,72,'bishop_march_doctrine'),
('G016','白昼演算官','SS',3.5,'亮晶晶',45,105,65,135,'bright_resonance_assault'),
('G017','灼轨先锋','B',1.5,'暖洋洋',70,20,75,35,'knight_assault_doctrine'),
('G018','余烬骑将','SS',3.5,'暖洋洋',125,45,105,70,'warm_resonance_assault'),
('G019','霜垒守门人','B',1.5,'冷冰冰',35,78,25,45,'rook_assault_doctrine'),
('G020','极夜壁垒','SS',3.5,'冷冰冰',75,135,40,85,'cold_resonance_assault'),
('G021','藤弦猎手','B',1.5,'郁萌萌',75,25,68,40,'bishop_assault_doctrine'),
('G022','苍森统领','SS',3.5,'郁萌萌',135,50,100,75,'green_resonance_assault'),
('G023','晨辉医官','B',1.5,'昼闪闪',25,50,65,80,'pawn_march_doctrine'),
('G024','天穹引航者','SS',3.5,'昼闪闪',65,90,115,130,'day_resonance_assault'),
('G025','静默观测员','B',1.5,'夜静静',25,60,48,85,'scout_march_doctrine'),
('G026','深宵解码者','SS',3.5,'夜静静',70,110,80,140,'night_resonance_assault');

-- 只处理本轮种子开始前尚未映射的模板代码；完成标记存在时目标集为空。 / Process only template codes that were unmapped before this seed run; the target set is empty once the completion marker exists.
DROP TEMPORARY TABLE IF EXISTS `fireseed_general_seed_targets`;
CREATE TEMPORARY TABLE `fireseed_general_seed_targets` (
  `template_code` varchar(64) NOT NULL,
  PRIMARY KEY (`template_code`)
) ENGINE=InnoDB;

INSERT INTO `fireseed_general_seed_targets` (`template_code`)
SELECT seed.`template_code`
FROM `fireseed_card_pool_general_seed` AS seed
JOIN `fireseed_resource_seed_gate` AS seed_gate
  ON seed_gate.`allowed` = 1
LEFT JOIN `general_template_catalog` AS catalog
  ON catalog.`template_code` = seed.`template_code`
WHERE catalog.`template_code` IS NULL;

INSERT INTO `generals`
(`owner_id`,`name`,`source`,`rarity`,`cost`,`element`,`level`,`hp`,`max_hp`,`attack`,`defense`,`speed`,`intelligence`,`is_active`)
SELECT
  0, seed.`name`,
  CONCAT('__resource_seed_20260717__:', seed.`template_code`),
  seed.`rarity`, seed.`cost`, seed.`element`,
  1, 100, 100, seed.`attack`, seed.`defense`, seed.`speed`, seed.`intelligence`, 1
FROM `fireseed_card_pool_general_seed` AS seed
JOIN `fireseed_general_seed_targets` AS seed_target
  ON seed_target.`template_code` = seed.`template_code`;

INSERT INTO `general_template_catalog` (`template_code`,`general_id`)
SELECT seed.`template_code`, general.`general_id`
FROM `fireseed_card_pool_general_seed` AS seed
JOIN `fireseed_general_seed_targets` AS seed_target
  ON seed_target.`template_code` = seed.`template_code`
JOIN `generals` AS general
  ON general.`owner_id` = 0
  AND general.`name` = seed.`name`
  AND general.`source` = CONCAT(
    '__resource_seed_20260717__:',
    seed.`template_code`
  )
  AND general.`rarity` = seed.`rarity`
;

UPDATE `generals` AS general
JOIN `general_template_catalog` AS catalog
  ON catalog.`general_id` = general.`general_id`
JOIN `fireseed_general_seed_targets` AS seed_target
  ON seed_target.`template_code` = catalog.`template_code`
SET general.`source` = '原创角色';

INSERT INTO `general_skills`
(`general_id`,`skill_type`,`skill_name`,`slot`,`skill_level`,`skill_effect`)
SELECT catalog.`general_id`, '自带', card.`name`, 0, 1, card.`effect_json`
FROM `general_template_catalog` AS catalog
JOIN `fireseed_general_seed_targets` AS seed_target
  ON seed_target.`template_code` = catalog.`template_code`
JOIN `fireseed_card_pool_general_seed` AS seed
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
JOIN `fireseed_general_seed_targets` AS seed_target
  ON seed_target.`template_code` = catalog.`template_code`
JOIN `fireseed_card_pool_general_seed` AS seed
  ON seed.`template_code` = catalog.`template_code`
JOIN `skill_card_catalog` AS card
  ON card.`card_code` = seed.`skill_card_code`
JOIN `general_skills` AS skill
  ON skill.`general_id` = catalog.`general_id`
  AND skill.`slot` = 0;

DROP TEMPORARY TABLE `fireseed_general_seed_targets`;
DROP TEMPORARY TABLE `fireseed_card_pool_general_seed`;

-- 资料站未公布抽取概率；以下仅迁移本项目原有非付费概率，并让旧库中的全部启用公共资源继续可抽。 / The archive does not publish draw odds; these migrate this project's existing non-paid rates and keep every active public resource in legacy databases drawable.
DROP TEMPORARY TABLE IF EXISTS `fireseed_card_pool_seed`;
CREATE TEMPORARY TABLE `fireseed_card_pool_seed` LIKE `card_pools`;

INSERT INTO `fireseed_card_pool_seed`
(`pool_code`,`pool_type`,`name`,`description`,`cost_json`,`allowed_counts_json`,`status`,`sort_order`) VALUES
('general_normal','general','常规契约','以四色基础资源进行的常驻武将契约。','{"bright":100,"warm":100,"cold":100,"green":100}','[1,5,10]','published',10),
('general_advanced','general','高级契约','加入昼闪闪与夜静静资源的高级武将契约。','{"bright":500,"warm":500,"cold":500,"green":500,"day":100,"night":100}','[1,5,10]','published',20),
('general_resonance','general','回路共鸣','消耗思考回路的高阶武将契约。','{"circuit_points":5}','[1,5,10]','published',30),
('skill_standard','skill','夜静技能卡池','消耗夜静静抽取技能卡的常驻卡池。','{"night":250}','[1,5,10]','published',10);

-- 在创建前快照本轮真正缺失的预设池；已有、归档或被运营调整过的同代码池均不属于种子目标。 / Snapshot seed pools that are genuinely absent before creation; existing, archived, or operator-managed pools with the same code are never seed targets.
DROP TEMPORARY TABLE IF EXISTS `fireseed_pool_seed_targets`;
CREATE TEMPORARY TABLE `fireseed_pool_seed_targets` (
  `pool_code` varchar(64) NOT NULL,
  `pool_type` enum('general','skill') NOT NULL,
  PRIMARY KEY (`pool_code`)
) ENGINE=InnoDB;

INSERT INTO `fireseed_pool_seed_targets` (`pool_code`,`pool_type`)
SELECT seed.`pool_code`, seed.`pool_type`
FROM `fireseed_card_pool_seed` AS seed
JOIN `fireseed_resource_seed_gate` AS seed_gate
  ON seed_gate.`allowed` = 1
LEFT JOIN `card_pools` AS existing
  ON existing.`pool_code` = seed.`pool_code`
WHERE existing.`pool_id` IS NULL;

INSERT INTO `card_pools`
(`pool_code`,`pool_type`,`name`,`description`,`cost_json`,`allowed_counts_json`,`status`,`sort_order`)
SELECT
  seed.`pool_code`, seed.`pool_type`, seed.`name`, seed.`description`,
  seed.`cost_json`, seed.`allowed_counts_json`, seed.`status`,
  seed.`sort_order`
FROM `fireseed_card_pool_seed` AS seed
JOIN `fireseed_pool_seed_targets` AS seed_target
  ON seed_target.`pool_code` = seed.`pool_code`
  AND seed_target.`pool_type` = seed.`pool_type`;

DROP TEMPORARY TABLE `fireseed_card_pool_seed`;

DROP TEMPORARY TABLE IF EXISTS `fireseed_pool_rarity_seed`;
CREATE TEMPORARY TABLE `fireseed_pool_rarity_seed` (
  `pool_code` varchar(64) NOT NULL,
  `rarity` enum('B','A','S','SS','P') NOT NULL,
  `rarity_weight` int(10) unsigned NOT NULL,
  PRIMARY KEY (`pool_code`,`rarity`)
) ENGINE=InnoDB;

INSERT INTO `fireseed_pool_rarity_seed`
(`pool_code`,`rarity`,`rarity_weight`) VALUES
('general_normal','B',7000000),
('general_normal','A',2500000),
('general_normal','S',500000),
('general_advanced','A',7000000),
('general_advanced','S',2500000),
('general_advanced','SS',500000),
('general_resonance','S',5000000),
('general_resonance','SS',3500000),
('general_resonance','P',1500000),
('skill_standard','B',5500000),
('skill_standard','A',3000000),
('skill_standard','S',1000000),
('skill_standard','SS',400000),
('skill_standard','P',100000);

INSERT INTO `general_pool_entries`
(`pool_id`,`general_id`,`weight`,`is_featured`)
SELECT
  pool.`pool_id`,
  general.`general_id`,
  GREATEST(1, ROUND(rarity_seed.`rarity_weight` / rarity_count.`card_count`)),
  0
FROM `fireseed_pool_rarity_seed` AS rarity_seed
JOIN `card_pools` AS pool
  ON pool.`pool_code` = rarity_seed.`pool_code`
  AND pool.`pool_type` = 'general'
JOIN `fireseed_pool_seed_targets` AS seed_target
  ON seed_target.`pool_code` = pool.`pool_code`
  AND seed_target.`pool_type` = 'general'
JOIN (
  SELECT `rarity`, COUNT(*) AS `card_count`
  FROM `generals`
  WHERE `owner_id` = 0
    AND `is_active` = 1
  GROUP BY `rarity`
) AS rarity_count
  ON rarity_count.`rarity` = rarity_seed.`rarity`
JOIN `generals` AS general
  ON general.`rarity` = rarity_seed.`rarity`
  AND general.`owner_id` = 0
  AND general.`is_active` = 1;

INSERT INTO `skill_pool_entries`
(`pool_id`,`card_id`,`weight`,`is_featured`)
SELECT
  pool.`pool_id`,
  card.`card_id`,
  GREATEST(1, ROUND(rarity_seed.`rarity_weight` / rarity_count.`card_count`)),
  0
FROM `fireseed_pool_rarity_seed` AS rarity_seed
JOIN `card_pools` AS pool
  ON pool.`pool_code` = rarity_seed.`pool_code`
  AND pool.`pool_type` = 'skill'
JOIN `fireseed_pool_seed_targets` AS seed_target
  ON seed_target.`pool_code` = pool.`pool_code`
  AND seed_target.`pool_type` = 'skill'
JOIN (
  SELECT `rarity`, COUNT(*) AS `card_count`
  FROM `skill_card_catalog`
  WHERE `is_active` = 1
  GROUP BY `rarity`
) AS rarity_count
  ON rarity_count.`rarity` = rarity_seed.`rarity`
JOIN `skill_card_catalog` AS card
  ON card.`rarity` = rarity_seed.`rarity`
  AND card.`is_active` = 1;

DROP TEMPORARY TABLE `fireseed_pool_seed_targets`;
DROP TEMPORARY TABLE `fireseed_pool_rarity_seed`;

INSERT INTO `game_config`
(`key`,`value`,`description`,`is_constant`,`category`)
SELECT
  'resource_catalog_seed_20260717',
  'complete',
  '资源目录与默认卡池升级种子已完成；用于阻止重跑恢复运营配置 / Resource catalog and default-pool upgrade seed completed; prevents reruns from restoring live configuration',
  1,
  'system'
FROM `fireseed_resource_seed_gate` AS seed_gate
WHERE seed_gate.`allowed` = 1;

DROP TEMPORARY TABLE `fireseed_resource_seed_gate`;
COMMIT;
